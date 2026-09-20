<?php

namespace Tests\Feature;

use App\Livewire\Visits\Panels\Dispense;
use App\Models\Dispensation;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Visit;
use App\Services\StockService;
use App\Support\CurrentHospital;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Dispensing against a visit. The single hardcoded `items[0][…]` form moved
 * to the Dispense repeater of the visit workspace (plan D2); the atomic
 * stock-deduct + bill still lives in DispensationService.
 */
class DispensationHttpTest extends TestCase
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

    /** @return array{0: Visit, 1: StockItem} */
    private function scenario(Hospital $h, string $onHand = '50'): array
    {
        app(CurrentHospital::class)->set($h->id);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $c = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $patient->id]);
        $item = StockItem::factory()->create(['hospital_id' => $h->id, 'sale_price' => '2.00']);
        app(StockService::class)->receive($item, $onHand, '1.00');

        return [$c, $item->fresh()];
    }

    public function test_pharmacist_dispenses_deducts_stock_and_bills(): void
    {
        $h = Hospital::factory()->create();
        [$c, $item] = $this->scenario($h);
        $this->acting($h, 'pharmacist');

        $component = Livewire::test(Dispense::class, ['visitId' => $c->id]);
        $component->call('picked', 'dispense-'.$component->get('items')[0]['uid'], $item->id)
            ->set('items.0.quantity', '10')
            ->call('dispense')
            ->assertHasNoErrors()
            ->assertDispatched('visit-updated');

        $this->assertSame('40.00', (string) $item->fresh()->current_quantity); // 50 - 10
        $this->assertSame(1, Dispensation::count());
        $this->assertSame(1, $c->orderItems()->count());
        $this->assertSame('20.00', (string) $c->orderItems()->first()->line_total); // 10 × 2.00
    }

    /** The repeater: several drugs handed out in one atomic transaction. */
    public function test_the_repeater_dispenses_every_row_atomically(): void
    {
        $h = Hospital::factory()->create();
        [$c, $itemA] = $this->scenario($h);
        app(CurrentHospital::class)->set($h->id);
        $itemB = StockItem::factory()->create(['hospital_id' => $h->id, 'sale_price' => '5.00']);
        app(StockService::class)->receive($itemB, '20', '3.00');
        $this->acting($h, 'pharmacist');

        $component = Livewire::test(Dispense::class, ['visitId' => $c->id]);
        $component->call('picked', 'dispense-'.$component->get('items')[0]['uid'], $itemA->id)
            ->set('items.0.quantity', '4')
            ->call('addRow')
            ->assertCount('items', 2);

        $component->call('picked', 'dispense-'.$component->get('items')[1]['uid'], $itemB->id)
            ->set('items.1.quantity', '2')
            ->call('dispense')
            ->assertHasNoErrors()
            ->assertCount('items', 1);      // the repeater resets to one blank row

        $this->assertSame(1, Dispensation::count());
        $this->assertSame(2, Dispensation::firstOrFail()->items()->count());
        $this->assertSame('46.00', (string) $itemA->fresh()->current_quantity);
        $this->assertSame('18.00', (string) $itemB->fresh()->current_quantity);
        $this->assertSame(2, $c->orderItems()->count());
        $this->assertSame(                                          // 4×2.00 and 2×5.00
            ['8.00', '10.00'],
            $c->orderItems()->reorder('order_items.id')->pluck('line_total')->map(fn ($v) => (string) $v)->all(),
        );
    }

    /** A shortfall on any row rolls the whole hand-out back and errors on that row. */
    public function test_insufficient_stock_rolls_back_and_errors_on_the_offending_row(): void
    {
        $h = Hospital::factory()->create();
        [$c, $itemA] = $this->scenario($h);
        app(CurrentHospital::class)->set($h->id);
        $itemB = StockItem::factory()->create(['hospital_id' => $h->id, 'sale_price' => '5.00']);
        app(StockService::class)->receive($itemB, '1', '3.00');
        $this->acting($h, 'pharmacist');

        $component = Livewire::test(Dispense::class, ['visitId' => $c->id]);
        $component->call('picked', 'dispense-'.$component->get('items')[0]['uid'], $itemA->id)
            ->set('items.0.quantity', '4')
            ->call('addRow');

        $component->call('picked', 'dispense-'.$component->get('items')[1]['uid'], $itemB->id)
            ->set('items.1.quantity', '9')            // only 1 on hand
            ->call('dispense')
            ->assertHasErrors('items.1.quantity');

        // Nothing at all happened: no dispensation, no charge, no stock movement.
        $this->assertDatabaseCount('dispensations', 0);
        $this->assertSame(0, $c->orderItems()->count());
        $this->assertSame('50.00', (string) $itemA->fresh()->current_quantity);
        $this->assertSame('1.00', (string) $itemB->fresh()->current_quantity);
    }

    public function test_a_row_can_be_removed_from_the_repeater(): void
    {
        $h = Hospital::factory()->create();
        [$c, $item] = $this->scenario($h);
        $this->acting($h, 'pharmacist');

        $component = Livewire::test(Dispense::class, ['visitId' => $c->id])
            ->call('addRow')
            ->assertCount('items', 2)
            ->call('removeRow', 1)
            ->assertCount('items', 1);

        $component->call('picked', 'dispense-'.$component->get('items')[0]['uid'], $item->id)
            ->set('items.0.quantity', '1')
            ->call('dispense')
            ->assertHasNoErrors();

        $this->assertSame(1, Dispensation::firstOrFail()->items()->count());
    }

    public function test_a_row_without_a_drug_is_rejected_inline(): void
    {
        $h = Hospital::factory()->create();
        [$c] = $this->scenario($h);
        $this->acting($h, 'pharmacist');

        Livewire::test(Dispense::class, ['visitId' => $c->id])
            ->set('items.0.quantity', '3')
            ->call('dispense')
            ->assertHasErrors('items.0.stock_item_id');

        $this->assertDatabaseCount('dispensations', 0);
    }

    public function test_role_without_dispense_permission_is_forbidden(): void
    {
        $h = Hospital::factory()->create();
        [$c] = $this->scenario($h);
        $this->acting($h, 'receptionist');

        Livewire::test(Dispense::class, ['visitId' => $c->id])->assertForbidden();
        $this->assertDatabaseCount('dispensations', 0);
    }

    public function test_cannot_dispense_another_hospitals_stock(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        [$cA] = $this->scenario($a);
        app(CurrentHospital::class)->set($b->id);
        $itemB = StockItem::factory()->create(['hospital_id' => $b->id]);

        $this->acting($a, 'pharmacist');

        // The picker never surfaces it, and a hand-crafted id fails validation.
        $component = Livewire::test(Dispense::class, ['visitId' => $cA->id]);
        $uid = $component->get('items')[0]['uid'];
        $component->call('picked', 'dispense-'.$uid, $itemB->id);
        $component->set('items.0.quantity', '1')
            ->call('dispense')
            ->assertHasErrors('items.0.stock_item_id');

        $this->assertDatabaseCount('dispensations', 0);
    }

    public function test_the_panel_is_tenant_isolated(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        [$cB] = $this->scenario($b);
        $this->acting($a, 'pharmacist');

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(Dispense::class, ['visitId' => $cB->id]);
    }
}
