<?php

namespace Tests\Feature;

use App\Enums\RadiologyOrderStatus;
use App\Livewire\RadiologyOrders\Show as RadiologyOrderShow;
use App\Livewire\Visits\Panels\RadiologyOrders as RadiologyOrdersPanel;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\RadiologyOrder;
use App\Models\RadiologyStudy;
use App\Models\User;
use App\Models\Visit;
use App\Services\RadiologyService;
use App\Support\CurrentHospital;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Radiology ordering end to end. Ordering moved to the RadiologyOrders panel of
 * the visit workspace (Phase 3); the narrative report and the status machine
 * live on App\Livewire\RadiologyOrders\Show.
 */
class RadiologyTest extends TestCase
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
        $study = RadiologyStudy::factory()->create(['hospital_id' => $h->id, 'price' => '40.00']);

        return [$c, $study];
    }

    public function test_order_snapshots_and_bills(): void
    {
        $h = Hospital::factory()->create();
        [$c, $study] = $this->scenario($h);

        $order = app(RadiologyService::class)->order($c, [$study->id], 'trauma', null);

        $this->assertSame(1, $order->items()->count());
        $this->assertSame(1, $c->orderItems()->count());
        $this->assertSame('40.00', (string) $c->orderItems()->first()->line_total);
    }

    public function test_doctor_orders_radiologist_reports_and_completes(): void
    {
        $h = Hospital::factory()->create();
        [$c, $study] = $this->scenario($h);
        $doctor = $this->user($h, 'doctor');
        $rad = $this->user($h, 'radiologist');

        Livewire::actingAs($doctor)->test(RadiologyOrdersPanel::class, ['visitId' => $c->id])
            ->set('study_ids', [$study->id])
            ->call('order')
            ->assertHasNoErrors()
            ->assertDispatched('visit-updated');

        $order = RadiologyOrder::firstOrFail();
        $this->assertSame(1, $c->orderItems()->count()); // ordered and billed together

        $component = Livewire::actingAs($rad)->test(RadiologyOrderShow::class, ['radiologyOrder' => $order->uuid])
            ->set('findings', 'No fracture seen.')
            ->set('impression', 'Normal chest.')
            ->call('saveReport')
            ->assertHasNoErrors();

        $this->assertSame('No fracture seen.', $order->fresh()->findings);
        $this->assertSame('Normal chest.', $order->fresh()->impression);

        foreach (['scheduled', 'performed', 'reported'] as $s) {
            $component->call('transition', $s);
        }
        $this->assertSame('reported', $order->fresh()->status->value);
        $this->assertNotNull($order->fresh()->reported_at);
    }

    public function test_skip_ahead_status_is_rejected(): void
    {
        $h = Hospital::factory()->create();
        [$c, $study] = $this->scenario($h);
        $order = app(RadiologyService::class)->order($c, [$study->id], null, null);

        $this->expectException(\RuntimeException::class);
        app(RadiologyService::class)->transition($order, RadiologyOrderStatus::Reported);
    }

    public function test_an_illegal_transition_is_refused_on_the_detail_page(): void
    {
        $h = Hospital::factory()->create();
        [$c, $study] = $this->scenario($h);
        $order = app(RadiologyService::class)->order($c, [$study->id], null, null);

        Livewire::actingAs($this->user($h, 'radiologist'))
            ->test(RadiologyOrderShow::class, ['radiologyOrder' => $order->uuid])
            ->call('transition', 'reported')   // ordered → reported is not a legal move
            ->assertDispatched('toast');

        $this->assertSame('ordered', $order->fresh()->status->value);
    }

    public function test_a_role_without_radiology_report_cannot_report_or_transition(): void
    {
        $h = Hospital::factory()->create();
        [$c, $study] = $this->scenario($h);
        $order = app(RadiologyService::class)->order($c, [$study->id], null, null);

        // A doctor may read the order but not report it.
        $doctor = $this->user($h, 'doctor');
        Livewire::actingAs($doctor)->test(RadiologyOrderShow::class, ['radiologyOrder' => $order->uuid])
            ->assertOk()
            ->set('findings', 'Sneaky edit.')
            ->call('saveReport')
            ->assertForbidden();

        Livewire::actingAs($doctor)->test(RadiologyOrderShow::class, ['radiologyOrder' => $order->uuid])
            ->call('transition', 'scheduled')
            ->assertForbidden();

        $this->assertNull($order->fresh()->findings);
        $this->assertSame('ordered', $order->fresh()->status->value);

        // A receptionist holds no radiology.view at all: the page itself is closed.
        Livewire::actingAs($this->user($h, 'receptionist'))
            ->test(RadiologyOrderShow::class, ['radiologyOrder' => $order->uuid])
            ->assertForbidden();
    }

    public function test_a_role_without_radiology_order_cannot_open_the_ordering_panel(): void
    {
        $h = Hospital::factory()->create();
        [$c] = $this->scenario($h);

        Livewire::actingAs($this->user($h, 'receptionist'))
            ->test(RadiologyOrdersPanel::class, ['visitId' => $c->id])
            ->assertForbidden();
        $this->assertDatabaseCount('radiology_orders', 0);
    }

    public function test_ordering_without_a_study_is_rejected_inline(): void
    {
        $h = Hospital::factory()->create();
        [$c] = $this->scenario($h);

        Livewire::actingAs($this->user($h, 'doctor'))
            ->test(RadiologyOrdersPanel::class, ['visitId' => $c->id])
            ->set('study_ids', [])
            ->call('order')
            ->assertHasErrors('study_ids');

        $this->assertDatabaseCount('radiology_orders', 0);
    }

    public function test_cannot_order_another_hospitals_study(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        [$cB, $studyB] = $this->scenario($b);
        [$cA] = $this->scenario($a);

        Livewire::actingAs($this->user($a, 'doctor'))
            ->test(RadiologyOrdersPanel::class, ['visitId' => $cA->id])
            ->set('study_ids', [$studyB->id])
            ->call('order')
            ->assertHasErrors('study_ids.0');

        $this->assertDatabaseCount('radiology_orders', 0);
    }

    public function test_the_ordering_panel_is_tenant_isolated(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        [$cB] = $this->scenario($b);
        $doctorA = $this->user($a, 'doctor');
        app(CurrentHospital::class)->set($a->id);

        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($doctorA)->test(RadiologyOrdersPanel::class, ['visitId' => $cB->id]);
    }

    public function test_pdf_renders_and_tenant_isolated(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        [$cB, $studyB] = $this->scenario($b);
        $orderB = app(RadiologyService::class)->order($cB, [$studyB->id], null, null);

        // Owner can render.
        $this->actingAs($this->user($b, 'radiologist'))->get("/admin/radiology-orders/{$orderB->uuid}/pdf")
            ->assertOk()->assertHeader('content-type', 'application/pdf');

        // Other hospital can't see it.
        $radA = $this->user($a, 'radiologist');
        $this->actingAs($radA)->get("/admin/radiology-orders/{$orderB->uuid}")->assertNotFound();

        app(CurrentHospital::class)->set($a->id);
        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($radA)->test(RadiologyOrderShow::class, ['radiologyOrder' => $orderB->uuid]);
    }
}
