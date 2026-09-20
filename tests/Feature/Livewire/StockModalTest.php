<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Stock\Index;
use App\Models\Hospital;
use App\Models\StockItem;
use App\Models\User;
use App\Services\StockService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StockModalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function actingAdmin(Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    public function test_modal_creates_item_with_uuid(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);

        Livewire::test(Index::class)
            ->call('create')
            ->set('name', 'Paracetamol 500mg')
            ->set('unit', 'tablet')
            ->set('cost_price', '100')
            ->set('sale_price', '150')
            ->set('reorder_level', '50')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $item = StockItem::where('name', 'Paracetamol 500mg')->first();
        $this->assertNotNull($item);
        $this->assertNotEmpty($item->uuid);
        $this->assertSame($h->id, $item->hospital_id);
    }

    public function test_modal_requires_prices(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);

        Livewire::test(Index::class)
            ->call('create')
            ->set('name', 'Item')
            ->set('unit', 'box')
            ->call('save')
            ->assertHasErrors(['cost_price', 'sale_price', 'reorder_level']);
    }

    public function test_quick_filter_resets_the_page(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);

        Livewire::test(Index::class)
            ->call('setPage', 3)
            ->set('filter', 'low')
            ->assertSet('paginators.page', 1);
    }

    public function test_item_with_movements_cannot_be_deleted(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        $item = StockItem::factory()->create(['hospital_id' => $h->id]);
        app(StockService::class)->receive($item, '10', '1.00');

        Livewire::test(Index::class)
            ->call('delete', $item->id)
            ->assertDispatched('toast', type: 'error');

        $this->assertNotNull(StockItem::find($item->id)); // still there
    }

    public function test_item_without_movements_is_deleted(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        $item = StockItem::factory()->create(['hospital_id' => $h->id]);

        Livewire::test(Index::class)->call('delete', $item->id)->assertDispatched('toast', type: 'success');

        $this->assertNull(StockItem::find($item->id));
    }

    public function test_modal_edits_item(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        $item = StockItem::factory()->create(['hospital_id' => $h->id, 'name' => 'Old Item']);

        Livewire::test(Index::class)
            ->call('edit', $item->id)
            ->assertSet('name', 'Old Item')
            ->set('name', 'New Item')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('New Item', $item->fresh()->name);
    }
}
