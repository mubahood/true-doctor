<?php

namespace Tests\Feature;

use App\Livewire\LabOrders\Show as LabOrderShow;
use App\Livewire\Visits\Panels\LabOrders as LabOrdersPanel;
use App\Models\Hospital;
use App\Models\LabOrder;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Services\LabService;
use App\Support\CurrentHospital;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Lab ordering end to end. Ordering moved to the LabOrders panel of the
 * visit workspace (Phase 3); results and the status machine live on
 * App\Livewire\LabOrders\Show.
 */
class LabHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        Livewire::withoutLazyLoading();   // the ordering panel is #[Lazy]
    }

    private function user(Hospital $h, string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function scenario(Hospital $h): array
    {
        app(CurrentHospital::class)->set($h->id);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $c = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $patient->id]);
        $test = LabTest::factory()->create(['hospital_id' => $h->id, 'price' => '15.00']);

        return [$c, $test];
    }

    public function test_doctor_orders_labs_then_tech_results_and_completes(): void
    {
        $h = Hospital::factory()->create();
        [$c, $test] = $this->scenario($h);
        $doctor = $this->user($h, 'doctor');
        $tech = $this->user($h, 'lab_technician');

        Livewire::actingAs($doctor)->test(LabOrdersPanel::class, ['visitId' => $c->id])
            ->set('test_ids', [$test->id])
            ->set('clinical_notes', 'rule out malaria')
            ->call('order')
            ->assertHasNoErrors()
            ->assertDispatched('visit-updated');

        $order = LabOrder::firstOrFail();
        $this->assertSame('rule out malaria', $order->clinical_notes);
        $this->assertSame(1, $c->orderItems()->count()); // billed

        $item = $order->items()->first();

        // Inline result entry: wire:model.blur writes through LabService.
        $component = Livewire::actingAs($tech)->test(LabOrderShow::class, ['labOrder' => $order->uuid])
            ->set("results.{$item->id}.result_value", 'Positive')
            ->set("results.{$item->id}.result_flag", 'abnormal')
            ->assertHasNoErrors();

        $this->assertSame('Positive', $item->fresh()->result_value);
        $this->assertSame('abnormal', $item->fresh()->result_flag->value);
        $this->assertNotNull($item->fresh()->resulted_at);

        // Advance through the machine.
        foreach (['collected', 'processing', 'completed'] as $s) {
            $component->call('transition', $s);
        }
        $this->assertSame('completed', $order->fresh()->status->value);
    }

    public function test_an_illegal_transition_is_refused(): void
    {
        $h = Hospital::factory()->create();
        [$c, $test] = $this->scenario($h);
        $order = app(LabService::class)->order($c, [$test->id], null, null);

        Livewire::actingAs($this->user($h, 'lab_technician'))
            ->test(LabOrderShow::class, ['labOrder' => $order->uuid])
            ->call('transition', 'completed')   // ordered → completed is not a legal move
            ->assertDispatched('toast');

        $this->assertSame('ordered', $order->fresh()->status->value);
    }

    public function test_a_role_without_lab_process_cannot_result_or_transition(): void
    {
        $h = Hospital::factory()->create();
        [$c, $test] = $this->scenario($h);
        $order = app(LabService::class)->order($c, [$test->id], null, null);
        $item = $order->items()->first();

        // A doctor may read the order but not process it.
        $doctor = $this->user($h, 'doctor');
        Livewire::actingAs($doctor)->test(LabOrderShow::class, ['labOrder' => $order->uuid])
            ->assertOk()
            ->call('transition', 'collected')
            ->assertForbidden();

        Livewire::actingAs($doctor)->test(LabOrderShow::class, ['labOrder' => $order->uuid])
            ->call('saveResult', $item->id)
            ->assertForbidden();

        $this->assertNull($item->fresh()->result_value);
        $this->assertSame('ordered', $order->fresh()->status->value);

        // A receptionist holds no lab.view at all: the page itself is closed.
        Livewire::actingAs($this->user($h, 'receptionist'))
            ->test(LabOrderShow::class, ['labOrder' => $order->uuid])
            ->assertForbidden();
    }

    public function test_pdf_renders(): void
    {
        $h = Hospital::factory()->create();
        [$c, $test] = $this->scenario($h);
        $order = app(LabService::class)->order($c, [$test->id], null, null);

        $res = $this->actingAs($this->user($h, 'lab_technician'))->get("/admin/lab-orders/{$order->uuid}/pdf");
        $res->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_receptionist_cannot_order_labs(): void
    {
        $h = Hospital::factory()->create();
        [$c, $test] = $this->scenario($h);

        Livewire::actingAs($this->user($h, 'receptionist'))
            ->test(LabOrdersPanel::class, ['visitId' => $c->id])
            ->assertForbidden();
        $this->assertDatabaseCount('lab_orders', 0);
    }

    public function test_ordering_without_a_test_is_rejected_inline(): void
    {
        $h = Hospital::factory()->create();
        [$c] = $this->scenario($h);

        Livewire::actingAs($this->user($h, 'doctor'))
            ->test(LabOrdersPanel::class, ['visitId' => $c->id])
            ->set('test_ids', [])
            ->call('order')
            ->assertHasErrors('test_ids');

        $this->assertDatabaseCount('lab_orders', 0);
    }

    public function test_cannot_order_another_hospitals_lab_test(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        [$cB, $testB] = $this->scenario($b);
        [$cA] = $this->scenario($a);

        Livewire::actingAs($this->user($a, 'doctor'))
            ->test(LabOrdersPanel::class, ['visitId' => $cA->id])
            ->set('test_ids', [$testB->id])
            ->call('order')
            ->assertHasErrors('test_ids.0');

        $this->assertDatabaseCount('lab_orders', 0);
    }

    public function test_the_ordering_panel_is_tenant_isolated(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        [$cB] = $this->scenario($b);
        $doctorA = $this->user($a, 'doctor');
        app(CurrentHospital::class)->set($a->id);

        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($doctorA)->test(LabOrdersPanel::class, ['visitId' => $cB->id]);
    }

    public function test_lab_orders_are_tenant_isolated(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        [$cB, $testB] = $this->scenario($b);
        $orderB = app(LabService::class)->order($cB, [$testB->id], null, null);

        $techA = $this->user($a, 'lab_technician');
        app(CurrentHospital::class)->set($a->id);

        $this->actingAs($techA)->get("/admin/lab-orders/{$orderB->uuid}")->assertNotFound();

        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($techA)->test(LabOrderShow::class, ['labOrder' => $orderB->uuid]);
    }
}
