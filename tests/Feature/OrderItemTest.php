<?php

namespace Tests\Feature;

use App\Enums\OrderItemStatus;
use App\Livewire\Visits\Panels\Charges;
use App\Models\Hospital;
use App\Models\OrderItem;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Billable service lines. Ordering and cancelling moved from
 * the medical-service controller to the Charges panel of the visit workspace
 * (Phase 3); the line maths still lives in BillingService.
 */
class OrderItemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        Livewire::withoutLazyLoading();   // the panel is #[Lazy]; test it mounted
    }

    private function user(Hospital $h, string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function acting(Hospital $h, string $role): User
    {
        $u = $this->user($h, $role);
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    public function test_order_a_service_line_snapshots_and_totals(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'receptionist');
        $c = Visit::factory()->create(['hospital_id' => $h->id]);
        $svc = Service::factory()->create(['hospital_id' => $h->id, 'name' => 'Suture', 'price' => '30.00']);

        Livewire::test(Charges::class, ['visitId' => $c->id])
            ->set('service_id', $svc->id)
            ->set('quantity', 2)
            ->call('addLine')
            ->assertHasNoErrors()
            ->assertSet('service_id', null);

        $line = OrderItem::firstOrFail();
        $this->assertSame('30.00', (string) $line->unit_price);
        $this->assertSame('60.00', (string) $line->line_total);
    }

    public function test_cannot_order_another_hospitals_service(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $this->acting($a, 'receptionist');
        $c = Visit::factory()->create(['hospital_id' => $a->id]);
        $svcB = Service::factory()->create(['hospital_id' => $b->id]);

        Livewire::test(Charges::class, ['visitId' => $c->id])
            ->set('service_id', $svcB->id)
            ->set('quantity', 1)
            ->call('addLine')
            ->assertHasErrors('service_id');

        $this->assertDatabaseCount('order_items', 0);
    }

    /**
     * A charge is cancelled where it was done, not where it is read.
     *
     * The bill used to carry its own `cancelLine`, which let a clerk strike a
     * dispensing off the bill while the drugs stayed off the shelf. Removal now
     * belongs to the order that raised the line, so the reversal and the charge
     * move together — but the charge typed straight onto the bill has an order
     * too, and comes off the same way.
     */
    public function test_a_charge_typed_onto_the_bill_is_cancelled_through_its_order(): void
    {
        $h = Hospital::factory()->create();
        $actor = $this->acting($h, 'receptionist');
        $c = Visit::factory()->create(['hospital_id' => $h->id]);
        $svc = Service::factory()->create(['hospital_id' => $h->id, 'price' => '10.00']);

        Livewire::test(Charges::class, ['visitId' => $c->id])
            ->set('service_id', $svc->id)
            ->call('addLine')
            ->assertHasNoErrors();

        $line = OrderItem::firstOrFail();
        $this->assertNotNull($line->order_id, 'a charge was raised with no order behind it');

        app(\App\Services\OrderService::class)->removeItem($line, $actor->id);

        $this->assertSame(OrderItemStatus::Cancelled, $line->fresh()->status);
    }

    /** A bill shows its own visit's lines and has no reach beyond them. */
    public function test_a_line_from_another_visit_is_out_of_reach(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'receptionist');
        $mine = Visit::factory()->create(['hospital_id' => $h->id]);
        $other = Visit::factory()->create(['hospital_id' => $h->id]);
        $svc = Service::factory()->create(['hospital_id' => $h->id, 'price' => '10.00']);

        app(\App\Services\BillingService::class)->orderService($other, $svc->id, 1);
        $foreignLine = OrderItem::firstOrFail();

        $component = Livewire::test(Charges::class, ['visitId' => $mine->id]);

        $this->assertSame([], $component->instance()->lines()->pluck('id')->all());

        // And the one control that does take a line id refuses a foreign one.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $component->call('openOrder', $foreignLine->id);
    }

    public function test_a_billing_reader_may_look_but_not_change_the_bill(): void
    {
        $h = Hospital::factory()->create();
        $c = Visit::factory()->create(['hospital_id' => $h->id]);
        $svc = Service::factory()->create(['hospital_id' => $h->id, 'price' => '10.00']);

        $reader = User::factory()->create(['hospital_id' => $h->id, 'role' => 'accountant']);
        $reader->syncRoles([]);
        $reader->givePermissionTo(['visits.view', 'billing.view']);
        $this->actingAs($reader);
        app(CurrentHospital::class)->set($h->id);

        Livewire::test(Charges::class, ['visitId' => $c->id])
            ->assertOk()
            ->set('service_id', $svc->id)
            ->call('addLine')
            ->assertForbidden();

        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_a_role_without_billing_visibility_cannot_open_the_panel(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'nurse');      // nurse holds neither billing.view nor billing.manage
        $c = Visit::factory()->create(['hospital_id' => $h->id]);

        Livewire::test(Charges::class, ['visitId' => $c->id])->assertForbidden();
    }
}
