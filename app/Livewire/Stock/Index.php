<?php

namespace App\Livewire\Stock;

use App\Http\Requests\StockItemRequest;
use App\Livewire\Concerns\CrudModal;
use App\Livewire\Concerns\ImportsSamples;
use App\Livewire\Concerns\MovesStock;
use App\Livewire\Concerns\PeeksStockItems;
use App\Livewire\Concerns\WithTable;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Support\SampleCatalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pharmacy stock register — name search + low-stock / expiring quick filters +
 * the slide-over create/edit form (CrudModal). Quantities are moved via the
 * separate receive/adjust flows; this form owns the item's catalogue metadata,
 * validated by StockItemRequest::rulesFor() — the single rule source.
 *
 * @property-read \App\Models\StockItem|null $moving
 * @property-read array{items:int,quantity:string,value:string,low:int,expiring:int,expired:int} $shelf
 * @property-read \App\Models\StockCategory|null $pinnedCategory
 * @property-read StockItem|null $peeked
 * @property-read \Illuminate\Support\Collection<int,\App\Models\StockMovement> $peekedHistory
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesRequests, CrudModal, ImportsSamples, MovesStock, PeeksStockItems, WithTable;

    #[Url(history: true, except: '')]
    public string $filter = '';

    /**
     * One shelf at a time.
     *
     * The categories page counts what is in each one and what is running out;
     * those counts are only useful if they lead somewhere, and this is where.
     */
    #[Url(history: true, except: '')]
    public string $category = '';

    public string $name = '';

    public ?int $stock_category_id = null;

    public ?string $sku = null;

    public string $unit = '';

    public ?string $batch_no = null;

    public ?string $expiry_date = null;

    public string $cost_price = '';

    public string $sale_price = '';

    public string $reorder_level = '';

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorize('viewAny', StockItem::class);
    }

    // ── CrudModal contract ─────────────────────────────────────
    protected function modelClass(): string
    {
        return StockItem::class;
    }

    protected function formFields(): array
    {
        return ['name', 'stock_category_id', 'sku', 'unit', 'batch_no', 'expiry_date', 'cost_price', 'sale_price', 'reorder_level', 'is_active'];
    }

    protected function nounLabel(): string
    {
        return 'Stock item';
    }

    protected function nullableFields(): array
    {
        return ['sku', 'batch_no', 'expiry_date'];
    }

    protected function defaults(): array
    {
        return ['is_active' => true];
    }

    protected function resetsPage(): array
    {
        return ['filter', 'category'];
    }

    /** @return list<string> */
    protected function sortableFields(): array
    {
        return ['name', 'current_quantity', 'current_stock_value', 'expiry_date', 'is_active'];
    }

    /** The row that moved is a row this page is drawing. */
    protected function afterStockMoved(): void
    {
        unset($this->shelf, $this->peeked);
    }

    /** A dialog should not land on top of the one that opened it. */
    protected function beforeActingOnStock(): void
    {
        $this->closePeek();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->filter !== '' || $this->category !== '';
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'filter', 'category']);
        $this->resetPage();
    }

    /** The category the list is pinned to, so the page can name it. */
    #[Computed]
    public function pinnedCategory(): ?\App\Models\StockCategory
    {
        return $this->category === '' ? null : \App\Models\StockCategory::find((int) $this->category);
    }

    /**
     * What the store holds, by the same rules the alerts page uses.
     *
     * @return array{items:int,quantity:string,value:string,low:int,expiring:int,expired:int}
     */
    #[Computed]
    public function shelf(): array
    {
        return StockItem::shelf();
    }

    protected function rules(): array
    {
        return StockItemRequest::rulesFor($this->editingId);
    }

    /** A new item gets its public identifier here; an edit never rewrites it. */
    protected function beforeSave(array &$data, ?Model $model): void
    {
        if ($model === null) {
            $data['uuid'] = (string) Str::uuid();
        }
    }

    /** Delete-protection: an item with movement history is audit evidence. */
    protected function assertDeletable(Model $model): void
    {
        if ($model instanceof StockItem && $model->movements()->exists()) {
            throw new \DomainException('This item has stock movements and cannot be deleted — deactivate it instead.');
        }
    }

    // ── Starter data import ────────────────────────────────────

    protected function sampleModelClass(): string
    {
        return StockItem::class;
    }

    protected function sampleSource(): array
    {
        return SampleCatalogue::stockItems();
    }

    /**
     * Put one sample drug on the shelf, with its opening stock.
     *
     * The quantity goes through StockService rather than onto the column: the
     * ledger is the only thing that may move a quantity, and an opening count
     * that never appeared in it would be a number with no history behind it.
     *
     * Idempotent — a second import adds neither the item nor the stock again.
     */
    protected function persistSample(array $row): void
    {
        $category = StockCategory::firstOrCreate(
            ['name' => $row['category']],
            ['unit' => $row['unit'] ?? 'unit', 'is_active' => true],
        );

        $item = StockItem::where('name', $row['name'])->first();

        if ($item !== null) {
            return;
        }

        $item = StockItem::create([
            'uuid' => (string) Str::uuid(),
            'stock_category_id' => $category->id,
            'name' => $row['name'],
            'unit' => $row['unit'] ?? 'unit',
            'original_quantity' => 0,
            'current_quantity' => 0,
            'cost_price' => $row['cost_price'] ?? 0,
            'sale_price' => $row['sale_price'] ?? 0,
            'current_stock_value' => 0,
            'reorder_level' => $row['reorder_level'] ?? 0,
            'is_active' => true,
        ]);

        $opening = (string) ($row['quantity'] ?? 0);
        if (bccomp($opening, '0', 2) > 0) {
            app(\App\Services\StockService::class)->receive(
                $item, $opening, (string) ($row['cost_price'] ?? 0), auth()->id(), 'Opening stock',
            );
        }
    }

    /** Category options — evaluated only while the modal renders. */
    #[Computed]
    public function categories()
    {
        return StockCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    public function render()
    {
        $this->authorize('viewAny', StockItem::class);

        $query = StockItem::with('category')
            ->listed($this->filter, $this->category, $this->search);

        /** @var Builder<StockItem> $sorted */
        $sorted = $this->applySort($query, fn (Builder $q) => $q->orderBy('name'));

        return view('livewire.stock.index', ['rows' => $sorted->paginate($this->perPage)])->title('Stock items');
    }
}
