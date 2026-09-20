<?php

namespace Tests\Feature;

use App\Livewire\Stock\Index as StockIndex;
use App\Livewire\Stock\Show as StockShow;
use App\Models\Hospital;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Notifications\LowStockAlert;
use App\Services\StockService;
use App\Support\CurrentHospital;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pharmacy register + item detail. Since Phase 3 the whole module is Livewire:
 * the catalogue form is Stock\Index's slide-over and the two quantity movements
 * plus the delete-protection live on Stock\Show (the classic
 * receive/adjust/destroy routes are gone).
 */
class StockHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function user(Hospital $h, string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    public function test_pharmacist_creates_and_receives_stock(): void
    {
        $h = Hospital::factory()->create();
        $pharm = $this->user($h, 'pharmacist');
        $this->actingAs($pharm);
        app(CurrentHospital::class)->set($h->id);

        // Items are created through the slide-over (the classic store route is gone).
        Livewire::test(StockIndex::class)
            ->call('create')
            ->set('name', 'Paracetamol')->set('unit', 'tablets')
            ->set('cost_price', '0.10')->set('sale_price', '0.25')->set('reorder_level', '100')
            ->set('is_active', true)
            ->call('save')
            ->assertHasNoErrors();

        $item = StockItem::firstOrFail();

        // …and quantities move through the detail page's Receive slide-over.
        Livewire::test(StockShow::class, ['stock' => $item->uuid])
            ->call('openReceive')
            ->assertSet('showReceive', true)
            ->set('quantity', '500')
            ->set('unit_cost', '0.10')
            ->set('note', 'PO-4412')
            ->call('receive')
            ->assertHasNoErrors()
            ->assertSet('showReceive', false)
            ->assertDispatched('toast', type: 'success');

        $this->assertSame('500.00', (string) $item->fresh()->current_quantity);
        $this->assertSame('50.00', (string) $item->fresh()->current_stock_value); // 500 × 0.10

        // Append-only ledger row with its balance snapshot.
        $movement = StockMovement::where('stock_item_id', $item->id)->firstOrFail();
        $this->assertSame('received', $movement->reason->value);
        $this->assertSame('500.00', (string) $movement->quantity);
        $this->assertSame('500.00', (string) $movement->balance_after);
        $this->assertSame('PO-4412', $movement->note);
        $this->assertSame($pharm->id, $movement->created_by);
    }

    public function test_receiving_over_dispensing_cannot_go_negative_via_adjust(): void
    {
        $h = Hospital::factory()->create();
        $pharm = $this->user($h, 'pharmacist');
        $this->actingAs($pharm);
        app(CurrentHospital::class)->set($h->id);
        $item = StockItem::factory()->create(['hospital_id' => $h->id]);
        app(StockService::class)->receive($item, '5', '1.00');

        Livewire::test(StockShow::class, ['stock' => $item->uuid])
            ->call('openAdjust')
            ->set('reason', 'wastage')
            ->set('quantity', '9')
            ->call('adjust')
            ->assertHasErrors('quantity')
            ->assertSet('showAdjust', true);

        $this->assertSame('5.00', (string) $item->fresh()->current_quantity);
        // The refused movement never reached the append-only ledger.
        $this->assertSame(1, StockMovement::where('stock_item_id', $item->id)->count());
    }

    public function test_an_adjustment_that_crosses_the_reorder_level_alerts_the_pharmacist(): void
    {
        Notification::fake();
        $h = Hospital::factory()->create();
        $pharm = $this->user($h, 'pharmacist');
        $this->actingAs($pharm);
        app(CurrentHospital::class)->set($h->id);
        $item = StockItem::factory()->create(['hospital_id' => $h->id, 'reorder_level' => '10']);
        app(StockService::class)->receive($item, '15', '1.00'); // above reorder — no alert

        Livewire::test(StockShow::class, ['stock' => $item->uuid])
            ->call('openAdjust')
            ->set('reason', 'wastage')
            ->set('quantity', '8')      // 15 → 7, crosses the reorder level
            ->call('adjust')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success');

        $this->assertSame('7.00', (string) $item->fresh()->current_quantity);
        $this->assertSame('7.00', (string) StockMovement::where('stock_item_id', $item->id)->latest('id')->firstOrFail()->balance_after);
        Notification::assertSentTo($pharm, LowStockAlert::class);
    }

    public function test_item_with_movements_cannot_be_deleted(): void
    {
        $h = Hospital::factory()->create();
        $pharm = $this->user($h, 'pharmacist');
        $this->actingAs($pharm);
        app(CurrentHospital::class)->set($h->id);
        $item = StockItem::factory()->create(['hospital_id' => $h->id]);
        app(StockService::class)->receive($item, '10', '1.00');

        Livewire::test(StockShow::class, ['stock' => $item->uuid])
            ->call('archive')
            ->assertDispatched('toast', type: 'error')
            ->assertNoRedirect();

        $this->assertNotNull(StockItem::find($item->id)); // still there

        // …it is deactivated instead, and the ledger is untouched.
        Livewire::test(StockShow::class, ['stock' => $item->uuid])
            ->call('deactivate')
            ->assertDispatched('toast', type: 'success');

        $this->assertFalse($item->fresh()->is_active);
        $this->assertSame(1, StockMovement::where('stock_item_id', $item->id)->count());
    }

    public function test_an_item_without_movements_is_archived_and_redirects_to_the_register(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAs($this->user($h, 'pharmacist'));
        app(CurrentHospital::class)->set($h->id);
        $item = StockItem::factory()->create(['hospital_id' => $h->id]);

        Livewire::test(StockShow::class, ['stock' => $item->uuid])
            ->call('archive')
            ->assertRedirect(route('admin.stock.index'));

        $this->assertNull(StockItem::find($item->id));
    }

    public function test_role_without_pharmacy_manage_cannot_create(): void
    {
        $h = Hospital::factory()->create();
        // Read-only pharmacy rights: may see the register, may not write to it.
        $viewer = $this->user($h, 'pharmacist');
        $viewer->syncRoles([]);
        $viewer->givePermissionTo(['access-admin', 'pharmacy.view']);
        $this->actingAs($viewer);
        app(CurrentHospital::class)->set($h->id);

        Livewire::test(StockIndex::class)->call('create')->assertForbidden();
    }

    public function test_role_without_pharmacy_manage_cannot_move_quantities(): void
    {
        $h = Hospital::factory()->create();
        $viewer = $this->user($h, 'pharmacist');
        $viewer->syncRoles([]);
        $viewer->givePermissionTo(['access-admin', 'pharmacy.view']);
        $this->actingAs($viewer);
        app(CurrentHospital::class)->set($h->id);
        $item = StockItem::factory()->create(['hospital_id' => $h->id]);

        Livewire::test(StockShow::class, ['stock' => $item->uuid])->assertOk()->call('openReceive')->assertForbidden();
        Livewire::test(StockShow::class, ['stock' => $item->uuid])->call('openAdjust')->assertForbidden();
        Livewire::test(StockShow::class, ['stock' => $item->uuid])->call('archive')->assertForbidden();
        Livewire::test(StockShow::class, ['stock' => $item->uuid])->call('deactivate')->assertForbidden();
    }

    public function test_stock_is_tenant_isolated(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $itemB = StockItem::factory()->create(['hospital_id' => $b->id, 'name' => 'SecretDrug']);

        $adminA = $this->user($a, 'hospital_admin');
        $this->actingAs($adminA)->get('/admin/stock')->assertDontSee('SecretDrug');
        $this->actingAs($adminA)->get("/admin/stock/{$itemB->uuid}")->assertNotFound();
    }

    public function test_another_tenants_item_cannot_be_mounted(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $itemB = StockItem::factory()->create(['hospital_id' => $b->id, 'name' => 'SecretDrug']);

        $this->actingAs($this->user($a, 'hospital_admin'));
        app(CurrentHospital::class)->set($a->id);

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(StockShow::class, ['stock' => $itemB->uuid]);
    }

    public function test_alerts_board_lists_low_and_expiring(): void
    {
        $h = Hospital::factory()->create();
        $admin = $this->user($h, 'hospital_admin');
        app(CurrentHospital::class)->set($h->id);
        StockItem::factory()->create(['hospital_id' => $h->id, 'name' => 'LowOne', 'current_quantity' => '2', 'reorder_level' => '10']);
        StockItem::factory()->create(['hospital_id' => $h->id, 'name' => 'ExpiringOne', 'current_quantity' => '50', 'reorder_level' => '1', 'expiry_date' => now()->addDays(30)->toDateString()]);

        // The board is a full-page Livewire component (plan Part III).
        $this->actingAs($admin)->get('/admin/stock/alerts')->assertOk()->assertSee('LowOne')->assertSee('ExpiringOne');

        Livewire::actingAs($admin)->test(\App\Livewire\Stock\Alerts::class)
            ->assertOk()
            ->assertSee('LowOne')
            ->assertSee('ExpiringOne');
    }
}
