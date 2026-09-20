<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Stock\Index;
use App\Models\Hospital;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\CurrentHospital;
use App\Support\SampleCatalogue;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Starter data for the pharmacy.
 *
 * Every other catalogue could be imported from the start and the drugs could
 * not, so a new hospital's pharmacy stayed empty and nothing could be
 * dispensed until somebody typed one in by hand.
 */
class StockSampleImportTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $pharmacist = User::factory()->create([
            'hospital_id' => $this->hospital->id, 'role' => 'pharmacist',
        ]);
        $pharmacist->syncSpatieRole();
        $this->actingAs($pharmacist);
    }

    public function test_the_register_offers_starter_items(): void
    {
        Livewire::test(Index::class)
            ->assertSee('Import samples')
            ->call('openSamples')
            ->assertSet('showSamples', true)
            ->assertSee('Paracetamol 500mg')
            ->assertSee('Normal saline 0.9% 500ml');
    }

    public function test_importing_puts_them_on_the_shelf(): void
    {
        Livewire::test(Index::class)->call('openSamples')->call('importSamples');

        $this->assertSame(count(SampleCatalogue::stockItems()), StockItem::count());

        $paracetamol = StockItem::where('name', 'Paracetamol 500mg')->firstOrFail();
        $this->assertSame('2000.00', (string) $paracetamol->current_quantity);
        $this->assertSame('150.00', (string) $paracetamol->sale_price);
        $this->assertTrue($paracetamol->is_active);
        $this->assertNotNull($paracetamol->category, 'the item landed with no category');
    }

    /**
     * Opening stock goes through the ledger.
     *
     * A quantity written straight onto the column would be a number with no
     * history behind it, and StockService is the only thing that may move one.
     */
    public function test_the_opening_count_has_a_ledger_row_behind_it(): void
    {
        Livewire::test(Index::class)->call('openSamples')->call('importSamples');

        $item = StockItem::where('name', 'Paracetamol 500mg')->firstOrFail();

        $movement = StockMovement::where('stock_item_id', $item->id)->firstOrFail();
        $this->assertSame('2000.00', (string) $movement->quantity);
        $this->assertSame('2000.00', (string) $movement->balance_after);
        $this->assertSame('Opening stock', $movement->note);
    }

    /** Twice adds neither the item nor the stock again. */
    public function test_importing_twice_changes_nothing(): void
    {
        Livewire::test(Index::class)->call('openSamples')->call('importSamples');

        $items = StockItem::count();
        $onHand = (string) StockItem::sum('current_quantity');
        $movements = StockMovement::count();

        Livewire::test(Index::class)->call('openSamples')->call('importSamples');

        $this->assertSame($items, StockItem::count());
        $this->assertSame($onHand, (string) StockItem::sum('current_quantity'), 'the shelf was double-stocked');
        $this->assertSame($movements, StockMovement::count());
    }

    /** What is already there is not offered a second time. */
    public function test_an_item_the_hospital_already_has_is_not_offered(): void
    {
        Livewire::test(Index::class)->call('openSamples')->call('importSamples');

        $component = Livewire::test(Index::class)->call('openSamples');

        $this->assertSame([], $component->get('samples'), 'everything was offered again');
    }

    /** Every sample must be sellable and orderable the moment it lands. */
    public function test_every_sample_arrives_ready_to_dispense(): void
    {
        Livewire::test(Index::class)->call('openSamples')->call('importSamples');

        foreach (StockItem::all() as $item) {
            $this->assertTrue($item->is_active, "{$item->name} arrived inactive");
            $this->assertGreaterThan(0, (float) $item->current_quantity, "{$item->name} arrived empty");
            $this->assertGreaterThan(0, (float) $item->sale_price, "{$item->name} arrived unpriced");
            $this->assertGreaterThan(
                (float) $item->cost_price,
                (float) $item->sale_price,
                "{$item->name} sells for less than it cost",
            );
        }
    }

    /** A role with no pharmacy rights never reaches the register, let alone the import. */
    public function test_a_role_without_pharmacy_rights_cannot_reach_it(): void
    {
        $reader = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'lab_technician']);
        $reader->syncSpatieRole();
        $this->actingAs($reader);

        $this->assertFalse($reader->can('pharmacy.view'));

        Livewire::test(Index::class)->assertForbidden();
    }

    /** And one who may look but not manage is refused the import itself. */
    public function test_a_role_that_may_only_look_cannot_import(): void
    {
        $looker = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor']);
        $looker->syncSpatieRole();
        $looker->givePermissionTo('pharmacy.view');
        $this->actingAs($looker);

        $this->assertFalse($looker->can('pharmacy.manage'));

        Livewire::test(Index::class)->call('openSamples')->assertForbidden();
        $this->assertSame(0, StockItem::count());
    }

    /** Another hospital's shelf is untouched by this one's import. */
    public function test_the_import_never_crosses_a_tenant_boundary(): void
    {
        $theirs = Hospital::factory()->create();

        Livewire::test(Index::class)->call('openSamples')->call('importSamples');

        app(CurrentHospital::class)->set($theirs->id);
        $this->assertSame(0, StockItem::count(), "the import reached another hospital's shelf");
    }
}
