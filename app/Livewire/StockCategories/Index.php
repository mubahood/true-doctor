<?php

namespace App\Livewire\StockCategories;

use App\Http\Requests\StockCategoryRequest;
use App\Livewire\Concerns\CrudModal;
use App\Livewire\Concerns\ImportsSamples;
use App\Livewire\Concerns\PeeksAndEditsRecords;
use App\Livewire\Concerns\WithTable;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Support\SampleCatalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Stock categories — the shelves, and what is on them.
 *
 * The page listed a name, a unit and a count of items, which says a category
 * exists and nothing about whether the store behind it is in trouble. A
 * storekeeper asks one question of this screen — WHAT IS RUNNING OUT — and it
 * could not answer, so every answer meant opening the stock list and reading
 * it by eye.
 *
 * Each row now carries what is actually on the shelf: how much is in store,
 * how many lines have fallen to their reorder level, how many are near their
 * expiry date, and what it is all worth. The thresholds are the ones the
 * alerts page already uses (StockItem::lowStock / expiringBefore), so the two
 * screens cannot come to disagree about what "running out" means.
 *
 * Validation is the same StockCategoryRequest::rulesFor() the rest of the app uses.
 *
 * @property-read array{categories:int,items:int,low:int,expiring:int,quantity:string,value:string} $store
 *
 * The counts in a row are links to a filtered shelf, which is right — but "what
 * IS on this shelf" is still a page away, and it is what somebody clicking a
 * count is about to ask. The dialog names the items behind the numbers.
 * @property-read StockCategory|null $peeked
 * @property-read Collection<int,StockItem> $peekedItems
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, CrudModal, ImportsSamples, PeeksAndEditsRecords, WithTable;

    public string $name = '';

    public string $unit = '';

    public ?string $description = null;

    public bool $is_active = true;

    /** '' = both; 'active' or 'inactive'. */
    #[Url(history: true, except: '')]
    public string $status = '';

    /** Only the shelves with something wrong on them. */
    #[Url(history: true, except: false)]
    public bool $attention = false;

    public function mount(): void
    {
        $this->authorize('viewAny', StockCategory::class);
    }

    // ── CrudModal contract ─────────────────────────────────────
    protected function modelClass(): string
    {
        return StockCategory::class;
    }

    protected function formFields(): array
    {
        return ['name', 'unit', 'description', 'is_active'];
    }

    protected function nounLabel(): string
    {
        return 'Category';
    }

    protected function nullableFields(): array
    {
        return ['description'];
    }

    protected function defaults(): array
    {
        return ['is_active' => true];
    }

    protected function rules(): array
    {
        return StockCategoryRequest::rulesFor($this->editingId);
    }

    protected function resetsPage(): array
    {
        return ['status', 'attention'];
    }

    /** @return list<string> */
    protected function sortableFields(): array
    {
        return ['name', 'items_count', 'on_hand', 'stock_value', 'low_count', 'is_active'];
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->status !== '' || $this->attention;
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'attention']);
        $this->resetPage();
    }

    /**
     * What the category being edited already holds.
     *
     * Renaming one that carries four hundred items is a different act from
     * renaming an empty one, and deleting is refused outright once it holds
     * anything (assertDeletable) — so the form says which it is before either.
     */
    public function editingSummary(): ?string
    {
        if ($this->editingId === null) {
            return null;
        }

        $category = StockCategory::withCount([
            'items',
            'items as low_count' => fn (Builder $q) => StockItem::applyLowStock($q->where('is_active', true)),
        ])->find($this->editingId);

        if ($category === null || $category->items_count === 0) {
            return null;
        }

        return $category->items_count.' '.\Illuminate\Support\Str::plural('item', $category->items_count)
            .' are in this category'
            .($category->low_count > 0 ? ', '.$category->low_count.' of them running out.' : '.');
    }

    // ── What is actually on the shelves ──────────────────────────────────

    /** The horizon the alerts page counts as "expiring soon", shared with it. */
    public const EXPIRY_HORIZON_DAYS = \App\Livewire\Stock\Alerts::EXPIRY_HORIZON_DAYS;

    /**
     * The store in one line, across every category.
     *
     * @return array{categories:int,items:int,low:int,expiring:int,quantity:string,value:string}
     */
    #[Computed]
    public function store(): array
    {
        $horizon = now()->addDays(self::EXPIRY_HORIZON_DAYS)->toDateString();

        return [
            'categories' => StockCategory::count(),
            'items' => StockItem::where('is_active', true)->count(),
            'low' => StockItem::where('is_active', true)->lowStock()->count(),
            'expiring' => StockItem::where('is_active', true)->expiringBefore($horizon)->count(),
            'quantity' => (string) (StockItem::where('is_active', true)->sum('current_quantity') ?: '0'),
            'value' => (string) (StockItem::where('is_active', true)->sum('current_stock_value') ?: '0'),
        ];
    }

    /** A category that still has stock items may not be removed (was StockCategoryController::destroy). */
    protected function assertDeletable(Model $model): void
    {
        if ($model instanceof StockCategory && $model->items()->exists()) {
            throw new \DomainException('This category has stock items — reassign them first.');
        }
    }

    // ── Starter data import ────────────────────────────────────
    protected function sampleModelClass(): string
    {
        return StockCategory::class;
    }

    protected function sampleSource(): array
    {
        return SampleCatalogue::stockCategories();
    }

    protected function persistSample(array $row): void
    {
        StockCategory::firstOrCreate(
            ['name' => $row['name']],
            ['unit' => $row['unit'] ?? 'unit', 'is_active' => true],
        );
    }

    // ── Reading one over the list ────────────────────────────────────────

    protected function peekModel(): string
    {
        return StockCategory::class;
    }

    protected function peekCaches(): array
    {
        return ['peekedItems'];
    }

    /**
     * What is actually on the shelf, worst first.
     *
     * Ordered by how little is left rather than by name: a category opened
     * because its "running out" count was red should lead with the thing that
     * is running out.
     *
     * @return Collection<int,StockItem>
     */
    #[Computed]
    public function peekedItems(): Collection
    {
        return $this->peekId === null
            ? new Collection
            : StockItem::where('stock_category_id', $this->peekId)
                ->where('is_active', true)
                ->orderBy('current_quantity')
                ->limit(8)
                ->get();
    }

    public function render()
    {
        $this->authorize('viewAny', StockCategory::class);

        $horizon = now()->addDays(self::EXPIRY_HORIZON_DAYS)->toDateString();

        // Counted in the query rather than per row: a page of twenty
        // categories would otherwise be sixty extra round trips, and the
        // aggregates have to be sortable columns anyway.
        $query = StockCategory::query()
            ->withCount([
                'items',
                'items as active_count' => fn (Builder $q) => $q->where('is_active', true),
                'items as low_count' => fn (Builder $q) => StockItem::applyLowStock($q->where('is_active', true)),
                'items as expiring_count' => fn (Builder $q) => StockItem::applyExpiringBefore($q->where('is_active', true), $horizon),
            ])
            ->withSum(['items as on_hand' => fn (Builder $q) => $q->where('is_active', true)], 'current_quantity')
            ->withSum(['items as stock_value' => fn (Builder $q) => $q->where('is_active', true)], 'current_stock_value')
            ->when($this->search !== '', fn (Builder $q) => $q->where('name', 'like', "%{$this->search}%"))
            ->when($this->status !== '', fn (Builder $q) => $q->where('is_active', $this->status === 'active'))
            ->when($this->attention, fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->whereHas('items', fn (Builder $i) => StockItem::applyLowStock($i->where('is_active', true)))
                ->orWhereHas('items', fn (Builder $i) => StockItem::applyExpiringBefore($i->where('is_active', true), $horizon))));

        /** @var Builder<StockCategory> $sorted */
        $sorted = $this->applySort($query, fn (Builder $q) => $q->orderBy('name'));

        return view('livewire.stock-categories.index', ['rows' => $sorted->paginate($this->perPage)])
            ->title('Stock categories');
    }
}
