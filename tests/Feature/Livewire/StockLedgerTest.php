<?php

namespace Tests\Feature\Livewire;

use App\Enums\StockMovementReason;
use App\Livewire\Stock\Alerts;
use App\Livewire\Stock\Index as StockList;
use App\Livewire\Stock\Movements as Ledger;
use App\Models\Hospital;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Stock accountability: how each item went out, or came in.
 *
 * The ledger has always existed — StockMovement is append-only, every row
 * carries the balance AFTER it, and StockService is the only writer — but it
 * could be read only ONE ITEM AT A TIME, on that item's own page, and there
 * was nowhere to act on a problem from the screen where you noticed it. So
 * nothing needed inventing; it needed showing and it needed reaching.
 */
class StockLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private StockCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        Carbon::setTestNow(Carbon::parse('2026-09-18 12:00:00'));

        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $admin = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin']);
        $admin->syncSpatieRole();
        $this->actingAs($admin);

        $this->category = StockCategory::create([
            'hospital_id' => $this->hospital->id, 'name' => 'Tablets', 'unit' => 'tablet', 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function item(string $name = 'Paracetamol', string $qty = '100', string $reorder = '20', ?string $expiry = null): StockItem
    {
        return StockItem::factory()->create([
            'hospital_id' => $this->hospital->id,
            'stock_category_id' => $this->category->id,
            'name' => $name,
            'unit' => 'tablet',
            'current_quantity' => $qty,
            'reorder_level' => $reorder,
            'cost_price' => '100',
            'current_stock_value' => bcmul($qty, '100', 2),
            'expiry_date' => $expiry,
            'is_active' => true,
        ]);
    }

    // ── Writing off what expired, with a reason ──────────────────────────

    /**
     * The thing a storekeeper needs and could not do: take expired stock off
     * the books from the screen that told them it had expired, and leave a
     * record of why.
     */
    public function test_expired_stock_is_written_off_with_its_reason_on_the_record(): void
    {
        $item = $this->item('Amoxicillin', '400', '50', '2026-08-01');

        Livewire::test(Alerts::class)
            ->call('openWriteOff', $item->id)
            ->assertSet('showMove', true)
            // Everything left, because writing off half a shelf of expired
            // stock and leaving the rest on the books is the mistake.
            ->assertSet('quantity', '400')
            // The commonest cause, already known from the item's own date.
            ->assertSet('reason', StockMovementReason::Expired->value)
            ->set('note', 'Expired 1 Aug 2026')
            ->call('saveMove')
            ->assertHasNoErrors();

        $this->assertSame(0, bccomp((string) $item->fresh()->current_quantity, '0', 2));

        $movement = StockMovement::where('stock_item_id', $item->id)->latest('id')->firstOrFail();
        // The CAUSE is on the ledger, not buried in a sentence: expired,
        // damaged and stolen are three problems with three different fixes.
        $this->assertSame(StockMovementReason::Expired, $movement->reason);
        $this->assertTrue($movement->reason->isLoss());
        $this->assertSame('Expired 1 Aug 2026', $movement->note);
        $this->assertSame(0, bccomp((string) $movement->balance_after, '0', 2));
    }

    /** "Wastage, 400 tablets" with no explanation is where an auditor stops. */
    public function test_a_write_off_without_a_reason_is_refused(): void
    {
        $item = $this->item('Amoxicillin', '400', '50', '2026-08-01');

        Livewire::test(Alerts::class)
            ->call('openWriteOff', $item->id)
            ->set('note', '')
            ->call('saveMove')
            ->assertHasErrors('note');

        $this->assertSame(0, bccomp((string) $item->fresh()->current_quantity, '400', 2));
    }

    public function test_more_than_the_shelf_holds_cannot_be_written_off(): void
    {
        $item = $this->item('Amoxicillin', '10', '5');

        Livewire::test(StockList::class)
            ->call('openWriteOff', $item->id)
            ->set('quantity', '50')
            ->set('note', 'Damaged in storage')
            ->call('saveMove')
            ->assertHasErrors('quantity');

        $this->assertSame(0, bccomp((string) $item->fresh()->current_quantity, '10', 2));
        $this->assertSame(0, StockMovement::where('stock_item_id', $item->id)->count());
    }

    // ── Receiving, from wherever the shortage was noticed ────────────────

    public function test_stock_is_received_straight_from_the_alert(): void
    {
        $item = $this->item('Paracetamol', '5', '20');

        Livewire::test(Alerts::class)
            ->call('openReceive', $item->id)
            ->set('quantity', '500')
            ->set('note', 'Delivery from supplier')
            ->call('saveMove')
            ->assertHasNoErrors();

        $this->assertSame(0, bccomp((string) $item->fresh()->current_quantity, '505', 2));
        $this->assertSame(
            StockMovementReason::Received,
            StockMovement::where('stock_item_id', $item->id)->latest('id')->firstOrFail()->reason,
        );
    }

    public function test_receiving_at_a_new_price_revalues_the_shelf(): void
    {
        $item = $this->item('Paracetamol', '100', '20');

        Livewire::test(StockList::class)
            ->call('openReceive', $item->id)
            ->set('quantity', '100')
            ->set('unit_cost', '150')
            ->call('saveMove')
            ->assertHasNoErrors();

        $fresh = $item->fresh();
        $this->assertSame(0, bccomp((string) $fresh->cost_price, '150', 2));
        $this->assertSame(0, bccomp((string) $fresh->current_stock_value, '30000', 2), '200 at 150');
    }

    public function test_an_adjustment_carries_the_reason_that_was_chosen(): void
    {
        $item = $this->item('Paracetamol', '100', '20');

        Livewire::test(StockList::class)
            ->call('openAdjust', $item->id)
            ->set('reason', StockMovementReason::AdjustmentIn->value)
            ->set('quantity', '7')
            ->set('note', 'Found during a count')
            ->call('saveMove')
            ->assertHasNoErrors();

        $movement = StockMovement::where('stock_item_id', $item->id)->latest('id')->firstOrFail();

        $this->assertSame(StockMovementReason::AdjustmentIn, $movement->reason);
        $this->assertSame(0, bccomp((string) $item->fresh()->current_quantity, '107', 2));
    }

    /** All three screens move stock the same way, or they will drift. */
    public function test_every_screen_that_moves_stock_does_it_the_same_way(): void
    {
        foreach ([StockList::class, Alerts::class] as $component) {
            $this->assertContains(
                \App\Livewire\Concerns\MovesStock::class,
                class_uses_recursive($component),
                $component.' has its own idea of what a write-off is',
            );
        }
    }

    // ── What it cost, and what it sold for ───────────────────────────────

    /**
     * The two questions a store is judged on, and the ledger could answer
     * neither: it kept the buying price only, and only on the way in.
     */
    public function test_receiving_records_both_prices_and_puts_them_on_the_item(): void
    {
        $item = $this->item('Paracetamol', '0', '20');

        Livewire::test(StockList::class)
            ->call('openReceive', $item->id)
            ->set('quantity', '500')
            ->set('unit_cost', '120')
            ->set('move_sale_price', '200')
            ->call('saveMove')
            ->assertHasNoErrors();

        $fresh = $item->fresh();
        $this->assertSame(0, bccomp((string) $fresh->cost_price, '120', 2));
        $this->assertSame(0, bccomp((string) $fresh->sale_price, '200', 2));

        $movement = StockMovement::where('stock_item_id', $item->id)->latest('id')->firstOrFail();
        $this->assertSame(0, bccomp((string) $movement->unit_cost, '120', 2));
        $this->assertSame(0, bccomp((string) $movement->unit_price, '200', 2));
    }

    /**
     * A supplier's invoice says "500 at 60,000", not "120 each". Asking
     * somebody to divide before they can type is asking for a mistake on the
     * record.
     */
    public function test_the_total_paid_works_out_the_unit_cost(): void
    {
        $item = $this->item('Paracetamol', '0', '20');

        Livewire::test(StockList::class)
            ->call('openReceive', $item->id)
            ->set('quantity', '500')
            ->set('total_cost', '60000')
            ->assertSet('unit_cost', '120');
    }

    public function test_the_unit_cost_works_out_the_total(): void
    {
        $item = $this->item('Paracetamol', '0', '20');

        Livewire::test(StockList::class)
            ->call('openReceive', $item->id)
            ->set('quantity', '500')
            ->set('unit_cost', '120')
            ->assertSet('total_cost', '60000');
    }

    /** Selling below cost is invisible between two boxes and obvious here. */
    public function test_the_dialog_shows_what_the_delivery_will_make(): void
    {
        $item = $this->item('Paracetamol', '0', '20');

        $page = Livewire::test(StockList::class)
            ->call('openReceive', $item->id)
            ->set('quantity', '500')
            ->set('unit_cost', '120')
            ->set('move_sale_price', '200');

        $preview = $page->instance()->marginPreview();

        $this->assertSame(0, bccomp($preview['cost'], '60000', 2));
        $this->assertSame(0, bccomp($preview['sale'], '100000', 2));
        $this->assertSame(0, bccomp($preview['margin'], '40000', 2));
    }

    public function test_a_price_entered_the_wrong_way_round_reads_as_a_loss(): void
    {
        $item = $this->item('Paracetamol', '0', '20');

        $preview = Livewire::test(StockList::class)
            ->call('openReceive', $item->id)
            ->set('quantity', '10')->set('unit_cost', '200')->set('move_sale_price', '120')
            ->instance()->marginPreview();

        $this->assertSame(-1, bccomp($preview['margin'], '0', 2));
    }

    /**
     * Every movement keeps the pair it happened under, so a repricing today
     * cannot rewrite what last month's sales looked like.
     */
    public function test_a_later_repricing_does_not_change_what_older_rows_were_worth(): void
    {
        $item = $this->item('Paracetamol', '0', '20');
        $stock = app(StockService::class);

        $stock->receive($item, '100', '100', null, null, '150');
        $stock->dispenseOut($item->fresh(), '10');

        $sold = StockMovement::where('reason', StockMovementReason::Dispensed->value)->latest('id')->firstOrFail();
        $this->assertSame(0, bccomp((string) $sold->margin(), '500', 2), '10 × (150 − 100)');

        // Re-priced upward a week later.
        $stock->receive($item->fresh(), '100', '400', null, null, '900');

        $this->assertSame(0, bccomp((string) $sold->fresh()->margin(), '500', 2), 'history must not move');
    }

    /** A write-off is a loss at COST, never a sale at a negative margin. */
    public function test_a_loss_is_valued_at_what_it_cost(): void
    {
        $item = $this->item('Paracetamol', '0', '20');
        $stock = app(StockService::class);

        $stock->receive($item, '100', '100', null, null, '150');
        $stock->adjust($item->fresh(), StockMovementReason::Expired, '40', null, 'Expired');

        $loss = StockMovement::where('reason', StockMovementReason::Expired->value)->firstOrFail();

        $this->assertSame(0, bccomp((string) $loss->costValue(), '4000', 2));
        $this->assertNull($loss->margin(), 'a write-off has no margin, it has a loss');
    }

    public function test_the_ledger_totals_what_was_bought_sold_and_lost(): void
    {
        $item = $this->item('Paracetamol', '0', '20');
        $stock = app(StockService::class);

        $stock->receive($item, '100', '100', null, null, '150');       // bought 10,000
        $stock->dispenseOut($item->fresh(), '20');                      // sold 3,000, cost 2,000
        $stock->adjust($item->fresh(), StockMovementReason::Expired, '10', null, 'Expired');  // lost 1,000

        $totals = Livewire::test(Ledger::class)->instance()->totals();

        $this->assertSame(0, bccomp($totals['bought'], '10000', 2));
        $this->assertSame(0, bccomp($totals['sold'], '3000', 2));
        $this->assertSame(0, bccomp($totals['margin'], '1000', 2));
        $this->assertSame(0, bccomp($totals['lost'], '1000', 2));
    }

    /**
     * Rows from before prices were kept are left OUT and said so, rather than
     * filled in with today's prices and called a profit.
     */
    public function test_movements_with_no_price_are_excluded_and_counted(): void
    {
        $item = $this->item('Paracetamol', '0', '20');
        app(StockService::class)->receive($item, '100', '100', null, null, '150');

        StockMovement::latest('id')->firstOrFail()->forceFill([
            'unit_cost' => null, 'unit_price' => null,
        ])->save();

        $totals = Livewire::test(Ledger::class)->instance()->totals();

        $this->assertSame(1, $totals['unpriced']);
        $this->assertSame(0, bccomp($totals['bought'], '0', 2));
    }

    // ── The record book ──────────────────────────────────────────────────

    public function test_the_ledger_shows_every_movement_with_the_balance_after_it(): void
    {
        $item = $this->item('Paracetamol', '0', '20');
        $stock = app(StockService::class);

        $stock->receive($item, '100', '100');
        $stock->adjust($item->fresh(), StockMovementReason::Wastage, '30', null, 'Broken in transit');

        $rows = Livewire::test(Ledger::class)->viewData('rows')->items();

        $this->assertCount(2, $rows);
        $this->assertSame(0, bccomp((string) $rows[0]->balance_after, '70', 2), 'newest first');
        $this->assertSame('Broken in transit', $rows[0]->note);
        $this->assertSame(0, bccomp((string) $rows[1]->balance_after, '100', 2));
    }

    public function test_the_ledger_totals_what_went_each_way(): void
    {
        $item = $this->item('Paracetamol', '0', '20');
        $stock = app(StockService::class);

        $stock->receive($item, '100', '100');
        $stock->receive($item->fresh(), '50', '100');
        $stock->adjust($item->fresh(), StockMovementReason::Wastage, '30', null, 'Expired');

        $totals = Livewire::test(Ledger::class)->instance()->totals();

        $this->assertSame(0, bccomp($totals['in'], '150', 2));
        $this->assertSame(0, bccomp($totals['out'], '30', 2));
        $this->assertSame(3, $totals['rows']);
    }

    public function test_the_ledger_can_be_narrowed_to_one_item(): void
    {
        $one = $this->item('Paracetamol', '0', '20');
        $two = $this->item('Amoxicillin', '0', '20');
        $stock = app(StockService::class);

        $stock->receive($one, '100', '100');
        $stock->receive($two, '80', '100');

        $rows = Livewire::test(Ledger::class)->set('item', (string) $one->id)->viewData('rows')->items();

        $this->assertCount(1, $rows);
        $this->assertSame($one->id, $rows[0]->stock_item_id);
    }

    public function test_the_ledger_can_be_narrowed_to_one_direction(): void
    {
        $item = $this->item('Paracetamol', '0', '20');
        $stock = app(StockService::class);

        $stock->receive($item, '100', '100');
        $stock->adjust($item->fresh(), StockMovementReason::Wastage, '10', null, 'Expired');

        $page = Livewire::test(Ledger::class);

        $this->assertCount(1, $page->set('direction', 'in')->viewData('rows')->items());
        $this->assertCount(1, $page->set('direction', 'out')->viewData('rows')->items());
    }

    public function test_the_ledger_can_be_narrowed_to_a_period(): void
    {
        $item = $this->item('Paracetamol', '0', '20');
        $stock = app(StockService::class);

        Carbon::setTestNow(Carbon::parse('2026-08-01 09:00:00'));
        $stock->receive($item, '100', '100');

        Carbon::setTestNow(Carbon::parse('2026-09-18 12:00:00'));
        $stock->receive($item->fresh(), '50', '100');

        $rows = Livewire::test(Ledger::class)->set('from', '2026-09-01')->viewData('rows')->items();

        $this->assertCount(1, $rows);
        $this->assertSame(0, bccomp((string) $rows[0]->quantity, '50', 2));
    }

    public function test_the_ledger_finds_a_movement_by_its_reason_in_words(): void
    {
        $item = $this->item('Paracetamol', '100', '20');
        app(StockService::class)->adjust($item, StockMovementReason::Wastage, '10', null, 'Contaminated batch');

        $rows = Livewire::test(Ledger::class)->set('search', 'contaminated')->viewData('rows')->items();

        $this->assertCount(1, $rows);
    }

    public function test_the_ledger_is_this_hospitals_ledger(): void
    {
        $mine = $this->item('Paracetamol', '0', '20');
        app(StockService::class)->receive($mine, '100', '100');

        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $theirCategory = StockCategory::create([
            'hospital_id' => $other->id, 'name' => 'Theirs', 'unit' => 'box', 'is_active' => true,
        ]);
        $theirs = StockItem::factory()->create([
            'hospital_id' => $other->id, 'stock_category_id' => $theirCategory->id,
            'current_quantity' => '0', 'reorder_level' => '1', 'cost_price' => '1', 'is_active' => true,
        ]);
        app(StockService::class)->receive($theirs, '999', '1');
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->assertCount(1, Livewire::test(Ledger::class)->viewData('rows')->items());
    }

    /**
     * The explanation is there when you want it, not every day.
     *
     * A page that opens with a paragraph about itself makes whoever read it
     * once scroll past it forever after. The words still say what the page is
     * FOR, which nothing else on the screen does, so they sit behind an (i).
     */
    public function test_the_page_explains_itself_without_taking_up_the_page(): void
    {
        $html = Livewire::test(Ledger::class)->html();

        $this->assertStringContainsString('tb-explain', $html, 'the (i) is not there');
        // A phrase that sits on one line of the template: the sentence itself
        // wraps, so matching across it would be matching the indentation.
        $this->assertStringContainsString('Nothing here can be edited', $html);

        // Behind the icon, not laid out as a block of prose above the table.
        $this->assertStringNotContainsString('tb-page-lede', $html);
    }

    /** A ledger that can be rewritten is not one. */
    public function test_the_ledger_offers_no_way_to_change_a_row(): void
    {
        $item = $this->item('Paracetamol', '100', '20');
        app(StockService::class)->adjust($item, StockMovementReason::Wastage, '10', null, 'Expired');

        $html = Livewire::test(Ledger::class)->html();

        $this->assertStringNotContainsString('wire:click="edit', $html);
        $this->assertStringNotContainsString('wire:click="delete', $html);
    }

    // ── The alerts board tells the two problems apart ────────────────────

    /**
     * Stock to order and stock to destroy are different problems, and one list
     * sorted by date buried the second among the first.
     */
    public function test_already_expired_is_separated_from_expiring_soon(): void
    {
        $gone = $this->item('Amoxicillin', '40', '5', '2026-08-01');
        $soon = $this->item('Ibuprofen', '40', '5', '2026-10-15');
        $fine = $this->item('Paracetamol', '40', '5', '2027-06-01');

        $page = Livewire::test(Alerts::class)->instance();

        $this->assertSame([$gone->id], $page->expired()->pluck('id')->all());
        $this->assertSame([$soon->id], $page->expiring()->pluck('id')->all());
        $this->assertNotContains($fine->id, $page->expiring()->pluck('id')->all());
    }

    /** Something expired with nothing left is not a problem any more. */
    public function test_an_empty_expired_shelf_is_not_listed(): void
    {
        $this->item('Amoxicillin', '0', '5', '2026-08-01');

        $this->assertCount(0, Livewire::test(Alerts::class)->instance()->expired());
    }

    public function test_the_board_says_what_the_expired_stock_is_worth(): void
    {
        $this->item('Amoxicillin', '40', '5', '2026-08-01');   // 40 × 100
        $this->item('Ibuprofen', '10', '5', '2026-07-01');     // 10 × 100

        $this->assertSame(0, bccomp(Livewire::test(Alerts::class)->instance()->atRisk()['value'], '5000', 2));
    }

    public function test_writing_off_takes_it_off_the_board(): void
    {
        $item = $this->item('Amoxicillin', '40', '5', '2026-08-01');

        $page = Livewire::test(Alerts::class)
            ->call('openWriteOff', $item->id)
            ->set('note', 'Expired')
            ->call('saveMove');

        $this->assertCount(0, $page->instance()->expired());
    }

    // ── Reading a row without leaving the list ───────────────────────────

    /**
     * The row shows six figures; the record has a dozen, and the useful part —
     * how it got to this number — was on a page of its own.
     */
    public function test_the_quick_view_shows_what_the_row_leaves_out(): void
    {
        $item = $this->item('Paracetamol', '120', '20');
        $item->update(['sku' => 'PARA-500', 'batch_no' => 'B-9912', 'sale_price' => '150']);

        Livewire::test(StockList::class)
            ->call('peek', $item->id)
            ->assertSet('showPeek', true)
            ->assertSee('PARA-500')
            ->assertSee('B-9912')
            ->assertSee('Reorder at');
    }

    /** The question somebody is actually asking: why is there only this much. */
    public function test_the_quick_view_shows_how_it_got_to_that_number(): void
    {
        $item = $this->item('Paracetamol', '0', '20');
        $stock = app(StockService::class);

        $stock->receive($item, '100', '100', null, 'Delivery from supplier', '150');
        $stock->adjust($item->fresh(), StockMovementReason::Expired, '10', null, 'Expired last month');

        Livewire::test(StockList::class)
            ->call('peek', $item->id)
            ->assertSee('Expired last month')
            ->assertSee('Delivery from supplier');
    }

    /** Capped: the whole story is the ledger, one click away. */
    public function test_the_quick_view_shows_only_the_last_few_movements(): void
    {
        $item = $this->item('Paracetamol', '0', '20');
        $stock = app(StockService::class);

        foreach (range(1, 9) as $i) {
            $stock->receive($item->fresh(), '10', '100');
        }

        $this->assertCount(6, Livewire::test(StockList::class)->call('peek', $item->id)->instance()->peekedHistory());
    }

    public function test_the_quick_view_links_to_the_whole_ledger(): void
    {
        $item = $this->item('Paracetamol', '120', '20');

        Livewire::test(StockList::class)->call('peek', $item->id)
            ->assertSee(route('admin.stock.movements', ['item' => $item->id]));
    }

    /** Two dialogs stacked over each other is a place to get lost in. */
    public function test_receiving_from_the_quick_view_replaces_it(): void
    {
        $item = $this->item('Paracetamol', '120', '20');

        Livewire::test(StockList::class)
            ->call('peek', $item->id)
            ->call('openReceive', $item->id)
            ->assertSet('showPeek', false)
            ->assertSet('showMove', true);
    }

    public function test_closing_the_quick_view_forgets_it(): void
    {
        $item = $this->item('Paracetamol', '120', '20');

        Livewire::test(StockList::class)->call('peek', $item->id)->call('closePeek')
            ->assertSet('showPeek', false)->assertSet('peekId', null);
    }

    public function test_another_hospitals_item_cannot_be_read(): void
    {
        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $theirCategory = StockCategory::create([
            'hospital_id' => $other->id, 'name' => 'Theirs', 'unit' => 'box', 'is_active' => true,
        ]);
        $theirs = StockItem::factory()->create([
            'hospital_id' => $other->id, 'stock_category_id' => $theirCategory->id,
            'current_quantity' => '50', 'reorder_level' => '1', 'is_active' => true,
        ]);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test(StockList::class)->call('peek', $theirs->id);
    }

    // ── A row you can point at ───────────────────────────────────────────

    /**
     * "The third one" means nothing once somebody sorts it differently, and
     * reading a drug name back over a phone is how the wrong thing gets
     * ordered.
     */
    public function test_every_row_carries_its_number_on_the_list(): void
    {
        foreach (['Amoxicillin', 'Paracetamol', 'Ibuprofen'] as $name) {
            $this->item($name, '100', '20');
        }

        $html = Livewire::test(StockList::class)->html();

        $this->assertStringContainsString('tb-serial', $html);
        $this->assertSame(
            4,   // the header cell plus one per row
            substr_count($html, 'tb-serial'),
        );
    }

    /** A serial that restarts on page two is not one. */
    public function test_the_numbering_counts_through_the_pages(): void
    {
        foreach (range(1, 12) as $i) {
            $this->item('Item '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), '100', '20');
        }

        $page = Livewire::test(StockList::class)->set('perPage', 10);

        $this->assertSame(1, $page->viewData('rows')->firstItem());
        $this->assertSame(11, $page->call('gotoPage', 2)->viewData('rows')->firstItem());
    }

    // ── The board sorts trouble by how much of it there is ───────────────

    /**
     * Zero is not "running out", it is work stopping.
     *
     * `lowStock()` is `quantity <= reorder_level`, which put an item at zero
     * beside one that had merely dipped below the line — and you cannot
     * dispense from the first at all.
     */
    public function test_nothing_left_is_its_own_lane(): void
    {
        $empty = $this->item('Amoxicillin', '0', '50');
        $low = $this->item('Paracetamol', '10', '50');
        $fine = $this->item('Ibuprofen', '500', '50');

        $page = Livewire::test(Alerts::class)->instance();

        $this->assertSame([$empty->id], $page->outOfStock()->pluck('id')->all());
        $this->assertSame([$low->id], $page->lowStock()->pluck('id')->all(), 'zero must not be counted twice');
        $this->assertNotContains($fine->id, $page->lowStock()->pluck('id')->all());
    }

    /** What it would cost to put every shortage right. */
    public function test_the_board_says_what_restocking_would_cost(): void
    {
        $this->item('Amoxicillin', '0', '50');      // short 50 × 100
        $this->item('Paracetamol', '20', '70');     // short 50 × 100

        $this->assertSame(0, bccomp(Livewire::test(Alerts::class)->instance()->atRisk()['shortfall'], '10000', 2));
    }

    public function test_a_row_says_how_much_would_put_it_right(): void
    {
        $item = $this->item('Paracetamol', '20', '70');

        $page = Livewire::test(Alerts::class)->instance();

        $this->assertSame(0, bccomp($page->shortBy($item), '50', 2));
        $this->assertSame(0, bccomp($page->shortfallValue($item), '5000', 2));
    }

    /** "In six days" and "in eighty-eight days" are not the same instruction. */
    public function test_an_expiry_inside_the_urgent_window_is_marked(): void
    {
        $soon = $this->item('Amoxicillin', '40', '5', now()->addDays(6)->toDateString());
        $later = $this->item('Ibuprofen', '40', '5', now()->addDays(80)->toDateString());

        $page = Livewire::test(Alerts::class)->instance();

        $this->assertSame('warn', $page->expiryTone($soon));
        $this->assertSame('', $page->expiryTone($later));
        $this->assertSame(6, $page->daysLeft($soon));
    }

    public function test_the_board_can_be_narrowed_to_one_shelf(): void
    {
        $mine = $this->item('Paracetamol', '5', '50');

        $other = StockCategory::create([
            'hospital_id' => $this->hospital->id, 'name' => 'Consumables', 'unit' => 'piece', 'is_active' => true,
        ]);
        StockItem::factory()->create([
            'hospital_id' => $this->hospital->id, 'stock_category_id' => $other->id,
            'name' => 'Gloves', 'current_quantity' => '2', 'reorder_level' => '50',
            'cost_price' => '100', 'is_active' => true,
        ]);

        $page = Livewire::test(Alerts::class)->set('category', (string) $this->category->id);

        $this->assertSame([$mine->id], $page->instance()->lowStock()->pluck('id')->all());
    }

    // ── A month's expiries in one sweep ──────────────────────────────────

    /**
     * Eleven expired lines is a sweep, not eleven separate decisions — but
     * every line still becomes its OWN ledger movement, with its own quantity
     * and its own balance. A single lumped row would be untraceable.
     */
    public function test_several_expired_lines_are_written_off_under_one_reason(): void
    {
        $a = $this->item('Amoxicillin', '40', '5', '2026-08-01');
        $b = $this->item('Ibuprofen', '25', '5', '2026-07-01');

        Livewire::test(Alerts::class)
            ->call('sweepAll')
            ->call('openSweep')
            ->assertSet('showSweep', true)
            ->set('sweepNote', 'Expiry sweep September 2026')
            ->call('runSweep')
            ->assertHasNoErrors()
            ->assertSet('showSweep', false);

        $this->assertSame(0, bccomp((string) $a->fresh()->current_quantity, '0', 2));
        $this->assertSame(0, bccomp((string) $b->fresh()->current_quantity, '0', 2));

        // One movement each, not one movement for both.
        $this->assertSame(1, StockMovement::where('stock_item_id', $a->id)->count());
        $this->assertSame(1, StockMovement::where('stock_item_id', $b->id)->count());

        $movement = StockMovement::where('stock_item_id', $a->id)->firstOrFail();
        $this->assertSame(StockMovementReason::Expired, $movement->reason);
        $this->assertSame('Expiry sweep September 2026', $movement->note);
        $this->assertSame(0, bccomp((string) $movement->quantity, '40', 2));
    }

    public function test_only_the_picked_lines_are_swept(): void
    {
        $picked = $this->item('Amoxicillin', '40', '5', '2026-08-01');
        $left = $this->item('Ibuprofen', '25', '5', '2026-07-01');

        Livewire::test(Alerts::class)
            ->call('toggleSweep', $picked->id)
            ->call('openSweep')
            ->set('sweepNote', 'Expiry sweep')
            ->call('runSweep');

        $this->assertSame(0, bccomp((string) $picked->fresh()->current_quantity, '0', 2));
        $this->assertSame(0, bccomp((string) $left->fresh()->current_quantity, '25', 2));
    }

    public function test_a_line_can_be_taken_back_out_of_the_sweep(): void
    {
        $item = $this->item('Amoxicillin', '40', '5', '2026-08-01');

        $page = Livewire::test(Alerts::class)
            ->call('toggleSweep', $item->id)
            ->call('toggleSweep', $item->id);

        $this->assertSame([], $page->get('sweeping'));
    }

    public function test_a_sweep_without_a_reason_is_refused(): void
    {
        $item = $this->item('Amoxicillin', '40', '5', '2026-08-01');

        Livewire::test(Alerts::class)
            ->call('sweepAll')
            ->call('openSweep')
            ->set('sweepNote', '')
            ->call('runSweep')
            ->assertHasErrors('sweepNote');

        $this->assertSame(0, bccomp((string) $item->fresh()->current_quantity, '40', 2));
    }

    public function test_the_sweep_says_what_it_is_about_to_take_off_the_books(): void
    {
        $this->item('Amoxicillin', '40', '5', '2026-08-01');   // 40 × 100
        $this->item('Ibuprofen', '25', '5', '2026-07-01');     // 25 × 100

        $page = Livewire::test(Alerts::class)->call('sweepAll');

        $this->assertSame(0, bccomp($page->instance()->sweepValue(), '6500', 2));
    }

    public function test_sweeping_nothing_does_nothing(): void
    {
        $this->item('Amoxicillin', '40', '5', '2026-08-01');

        Livewire::test(Alerts::class)->call('openSweep')->assertSet('showSweep', false);

        $this->assertSame(0, StockMovement::count());
    }

    /** The board is the same record as the list, so it opens the same view. */
    public function test_the_board_opens_the_same_quick_view_as_the_list(): void
    {
        $item = $this->item('Amoxicillin', '40', '5', '2026-08-01');

        Livewire::test(Alerts::class)->call('peek', $item->id)->assertSet('showPeek', true)->assertSee('Reorder at');

        foreach ([StockList::class, Alerts::class] as $component) {
            $this->assertContains(
                \App\Livewire\Concerns\PeeksStockItems::class,
                class_uses_recursive($component),
                $component.' has its own idea of what one item looks like',
            );
        }
    }

    // ── Who may ──────────────────────────────────────────────────────────

    /**
     * Reading the store is not moving it.
     *
     * No seeded role holds `pharmacy.view` without `pharmacy.manage`, so the
     * permission is granted directly: the point is the POLICY, not which role
     * happens to carry it today.
     */
    public function test_somebody_who_may_only_look_cannot_move_stock(): void
    {
        $item = $this->item('Paracetamol', '100', '20');

        $looker = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'records_officer']);
        $looker->syncSpatieRole();
        $looker->givePermissionTo('pharmacy.view');
        $this->actingAs($looker);

        Livewire::test(StockList::class)->assertOk();
        Livewire::test(StockList::class)->call('openWriteOff', $item->id)->assertForbidden();

        $this->assertSame(0, bccomp((string) $item->fresh()->current_quantity, '100', 2));
        $this->assertSame(0, StockMovement::where('stock_item_id', $item->id)->count());
    }

    /** …and somebody with no pharmacy right at all cannot open it. */
    public function test_a_role_with_no_pharmacy_right_cannot_open_the_store(): void
    {
        $nurse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $nurse->syncSpatieRole();

        $this->actingAs($nurse)->get(route('admin.stock.index'))->assertForbidden();
        $this->actingAs($nurse)->get(route('admin.stock.movements'))->assertForbidden();
    }

    public function test_another_hospitals_item_cannot_be_moved(): void
    {
        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $theirCategory = StockCategory::create([
            'hospital_id' => $other->id, 'name' => 'Theirs', 'unit' => 'box', 'is_active' => true,
        ]);
        $theirs = StockItem::factory()->create([
            'hospital_id' => $other->id, 'stock_category_id' => $theirCategory->id,
            'current_quantity' => '50', 'reorder_level' => '1', 'is_active' => true,
        ]);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test(StockList::class)->call('openWriteOff', $theirs->id);
    }
}
