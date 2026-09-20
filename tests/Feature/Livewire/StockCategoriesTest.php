<?php

namespace Tests\Feature\Livewire;

use App\Livewire\StockCategories\Index as Categories;
use App\Models\Hospital;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Models\User;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Stock categories — the shelves, and what is on them.
 *
 * The page listed a name, a unit and a count of items, which says a category
 * exists and nothing about whether the store behind it is in trouble. A
 * storekeeper asks this screen one question — WHAT IS RUNNING OUT — and it
 * could not answer.
 */
class StockCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function category(string $name = 'Tablets'): StockCategory
    {
        return StockCategory::create([
            'hospital_id' => $this->hospital->id,
            'name' => $name, 'unit' => 'tablet', 'is_active' => true,
        ]);
    }

    private function item(StockCategory $category, string $qty, string $reorder, ?string $expiry = null, bool $active = true, string $value = '0'): StockItem
    {
        return StockItem::factory()->create([
            'hospital_id' => $this->hospital->id,
            'stock_category_id' => $category->id,
            'current_quantity' => $qty,
            'reorder_level' => $reorder,
            'current_stock_value' => $value,
            'expiry_date' => $expiry,
            'is_active' => $active,
        ]);
    }

    /** @return StockCategory the row the page drew for this category */
    private function row(StockCategory $category): StockCategory
    {
        return collect(Livewire::test(Categories::class)->viewData('rows')->items())
            ->firstOrFail(fn (StockCategory $r) => $r->id === $category->id);
    }

    // ── What is on the shelf ─────────────────────────────────────────────

    public function test_a_row_shows_how_much_is_in_store(): void
    {
        $category = $this->category();
        $this->item($category, '120', '20');
        $this->item($category, '30.5', '10');

        $row = $this->row($category);

        $this->assertSame(2, $row->items_count);
        $this->assertSame(0, bccomp((string) $row->on_hand, '150.5', 2));
    }

    /**
     * The question the page exists to answer, and the one it could not.
     */
    public function test_a_row_shows_how_many_lines_are_running_out(): void
    {
        $category = $this->category();
        $this->item($category, '5', '10');    // below
        $this->item($category, '10', '10');   // at the level — also running out
        $this->item($category, '80', '10');   // fine

        $this->assertSame(2, $this->row($category)->low_count);
    }

    public function test_a_row_shows_how_many_are_near_their_expiry(): void
    {
        $category = $this->category();
        $this->item($category, '50', '10', '2026-10-01');   // within 90 days
        $this->item($category, '50', '10', '2027-06-01');   // beyond
        $this->item($category, '50', '10', null);           // never expires

        $this->assertSame(1, $this->row($category)->expiring_count);
    }

    public function test_a_row_shows_what_the_shelf_is_worth(): void
    {
        $category = $this->category();
        $this->item($category, '10', '2', null, true, '40000');
        $this->item($category, '10', '2', null, true, '15000');

        $this->assertSame(0, bccomp((string) $this->row($category)->stock_value, '55000', 2));
    }

    /** An item nobody stocks any more is not what is in the store. */
    public function test_an_inactive_item_counts_towards_nothing_but_the_total(): void
    {
        $category = $this->category();
        $this->item($category, '100', '10', null, true, '50000');
        $this->item($category, '1', '10', '2026-10-01', false, '99999');

        $row = $this->row($category);

        $this->assertSame(2, $row->items_count, 'it is still in the category');
        $this->assertSame(1, $row->active_count);
        $this->assertSame(0, $row->low_count, 'a retired item is not running out');
        $this->assertSame(0, $row->expiring_count);
        $this->assertSame(0, bccomp((string) $row->on_hand, '100', 2));
    }

    /** An empty category reads as empty, not as zero of everything. */
    public function test_an_empty_category_has_nothing_to_report(): void
    {
        $row = $this->row($this->category());

        $this->assertSame(0, $row->items_count);
        $this->assertSame(0, $row->low_count);
    }

    // ── One definition of "running out" ──────────────────────────────────

    /**
     * The categories page and the alerts page must agree about which items are
     * in trouble; two thresholds would be worse than either being wrong alone.
     */
    public function test_the_threshold_is_the_one_the_alerts_page_uses(): void
    {
        $category = $this->category();
        $this->item($category, '5', '10');
        $this->item($category, '80', '10');

        $this->assertSame(
            StockItem::where('is_active', true)->lowStock()->count(),
            $this->row($category)->low_count,
        );
    }

    public function test_the_expiry_horizon_is_shared_with_the_alerts_page(): void
    {
        $this->assertSame(
            \App\Livewire\Stock\Alerts::EXPIRY_HORIZON_DAYS,
            Categories::EXPIRY_HORIZON_DAYS,
        );
    }

    // ── The whole store, across the shelves ──────────────────────────────

    public function test_the_headline_adds_up_every_shelf(): void
    {
        $tablets = $this->category('Tablets');
        $syrups = $this->category('Syrups');
        $this->item($tablets, '5', '10', '2026-10-01', true, '1000');
        $this->item($syrups, '200', '10', null, true, '4000');

        $store = Livewire::test(Categories::class)->instance()->store();

        $this->assertSame(2, $store['categories']);
        $this->assertSame(2, $store['items']);
        $this->assertSame(1, $store['low']);
        $this->assertSame(1, $store['expiring']);
        $this->assertSame(0, bccomp($store['quantity'], '205', 2));
        $this->assertSame(0, bccomp($store['value'], '5000', 2));
    }

    public function test_the_store_is_this_hospitals_store(): void
    {
        $mine = $this->category();
        $this->item($mine, '10', '2');

        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $theirs = StockCategory::create([
            'hospital_id' => $other->id, 'name' => 'Theirs', 'unit' => 'box', 'is_active' => true,
        ]);
        StockItem::factory()->create([
            'hospital_id' => $other->id, 'stock_category_id' => $theirs->id,
            'current_quantity' => '999', 'reorder_level' => '1', 'is_active' => true,
        ]);
        app(CurrentHospital::class)->set($this->hospital->id);

        $store = Livewire::test(Categories::class)->instance()->store();

        $this->assertSame(1, $store['categories']);
        $this->assertSame(0, bccomp($store['quantity'], '10', 2));
    }

    // ── Filters and sorting ──────────────────────────────────────────────

    public function test_needs_attention_shows_only_the_shelves_with_a_problem(): void
    {
        $bad = $this->category('Tablets');
        $fine = $this->category('Syrups');
        $this->item($bad, '2', '10');
        $this->item($fine, '200', '10');

        $page = Livewire::test(Categories::class)->set('attention', true);

        $names = collect($page->viewData('rows')->items())->pluck('name')->all();

        $this->assertSame(['Tablets'], $names);
    }

    public function test_an_expiring_shelf_also_needs_attention(): void
    {
        $category = $this->category('Vaccines');
        $this->item($category, '500', '10', '2026-10-01');

        $names = collect(Livewire::test(Categories::class)->set('attention', true)->viewData('rows')->items())
            ->pluck('name')->all();

        $this->assertSame(['Vaccines'], $names);
    }

    public function test_the_status_filter_narrows_to_one_or_the_other(): void
    {
        $this->category('Active one');
        StockCategory::create([
            'hospital_id' => $this->hospital->id, 'name' => 'Retired', 'unit' => 'box', 'is_active' => false,
        ]);

        $page = Livewire::test(Categories::class);

        $this->assertCount(2, $page->viewData('rows')->items());
        $this->assertCount(1, $page->set('status', 'active')->viewData('rows')->items());
        $this->assertCount(1, $page->set('status', 'inactive')->viewData('rows')->items());
    }

    /** The aggregates have to be sortable, or a big store cannot be read. */
    public function test_the_shelves_can_be_ordered_by_what_is_on_them(): void
    {
        $small = $this->category('Small');
        $big = $this->category('Big');
        $this->item($small, '5', '1');
        $this->item($big, '900', '1');

        $names = collect(
            Livewire::test(Categories::class)->call('sortBy', 'on_hand')->viewData('rows')->items()
        )->pluck('name')->all();

        $this->assertSame(['Small', 'Big'], $names, 'ascending first');
    }

    public function test_a_hand_typed_sort_column_is_refused(): void
    {
        Livewire::test(Categories::class)->call('sortBy', 'hospital_id')->assertSet('sortField', '');
        Livewire::test(Categories::class)->call('sortBy', 'low_count')->assertSet('sortField', 'low_count');
    }

    public function test_clearing_puts_every_filter_back(): void
    {
        Livewire::test(Categories::class)
            ->set('search', 'tab')->set('status', 'active')->set('attention', true)
            ->call('clearFilters')
            ->assertSet('search', '')->assertSet('status', '')->assertSet('attention', false);
    }

    // ── The counts lead somewhere ────────────────────────────────────────

    /** A count nobody can open is a number, not an answer. */
    public function test_each_count_links_to_the_items_behind_it(): void
    {
        $category = $this->category();
        $this->item($category, '2', '10');

        // Escaped, because that is how a URL with a query string reaches the
        // page: `&` is `&amp;` in an href.
        Livewire::test(Categories::class)
            ->assertSee(route('admin.stock.index', ['category' => $category->id]))
            ->assertSee(route('admin.stock.index', ['category' => $category->id, 'filter' => 'low']));
    }

    public function test_the_stock_list_can_be_narrowed_to_one_shelf(): void
    {
        $tablets = $this->category('Tablets');
        $syrups = $this->category('Syrups');
        $mine = $this->item($tablets, '10', '2');
        $this->item($syrups, '10', '2');

        $rows = Livewire::test(\App\Livewire\Stock\Index::class)
            ->set('category', (string) $tablets->id)
            ->viewData('rows');

        $this->assertSame([$mine->id], collect($rows->items())->pluck('id')->all());
    }

    // ── The form ─────────────────────────────────────────────────────────

    public function test_the_form_says_what_the_category_already_holds(): void
    {
        $category = $this->category();
        $this->item($category, '2', '10');
        $this->item($category, '80', '10');

        $summary = Livewire::test(Categories::class)->call('edit', $category->id)->instance()->editingSummary();

        $this->assertStringContainsString('2 items are in this category', (string) $summary);
        $this->assertStringContainsString('1 of them running out', (string) $summary);
    }

    public function test_an_empty_category_needs_no_such_warning(): void
    {
        $category = $this->category();

        $this->assertNull(Livewire::test(Categories::class)->call('edit', $category->id)->instance()->editingSummary());
    }

    /** Reassign first — a category is not a thing you can lose items inside. */
    public function test_a_category_holding_items_cannot_be_deleted(): void
    {
        $category = $this->category();
        $this->item($category, '10', '2');

        Livewire::test(Categories::class)->call('delete', $category->id);

        $this->assertNotNull($category->fresh());
    }

    public function test_an_empty_category_can_be_deleted(): void
    {
        $category = $this->category();

        Livewire::test(Categories::class)->call('delete', $category->id);

        $this->assertSoftDeleted($category);
    }
}
