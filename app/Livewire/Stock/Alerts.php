<?php

namespace App\Livewire\Stock;

use App\Enums\StockMovementReason;
use App\Livewire\Concerns\MovesStock;
use App\Livewire\Concerns\PeeksStockItems;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Services\StockService;
use App\Support\HospitalSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Inventory alerts — sorted by how much trouble each thing is, and actionable.
 *
 * The board is four lanes, worst first, because they are four different
 * problems and one list of "alerts" made the reader work out which was which:
 *
 *  1. OUT OF STOCK — nothing left at all. `lowStock()` is
 *     `quantity <= reorder_level`, which put an item at ZERO beside one that
 *     had merely dipped below the line. They are not the same: zero is not a
 *     warning about the future, it is work stopping now.
 *  2. ALREADY EXPIRED — stock to destroy, not stock to order, and still
 *     counted in what the hospital says it holds.
 *  3. RUNNING OUT — below the reorder level, with how much would put it right.
 *  4. EXPIRING SOON — still usable, use it first. Banded, because "in six
 *     days" and "in eighty-eight days" are not the same instruction.
 *
 * Every row acts where it is: receive against a shortage, write off what has
 * expired, and — because a month's expiries is a sweep and not eleven separate
 * decisions — write off SEVERAL at once under one reason.
 *
 * @property-read Collection<int,StockItem> $outOfStock
 * @property-read Collection<int,StockItem> $lowStock
 * @property-read Collection<int,StockItem> $expiring
 * @property-read Collection<int,StockItem> $expired
 * @property-read StockItem|null $moving
 * @property-read array{out:int,low:int,expiring:int,expired:int,value:string,shortfall:string} $atRisk
 * @property-read Collection<int,StockCategory> $categories
 * @property-read StockItem|null $peeked
 * @property-read \Illuminate\Support\Collection<int,\App\Models\StockMovement> $peekedHistory
 * @property-read StockCategory|null $pinnedCategory
 */
#[Layout('layouts.admin')]
class Alerts extends Component
{
    use AuthorizesRequests, MovesStock, PeeksStockItems;

    /** Days ahead counted as "expiring soon". */
    public const EXPIRY_HORIZON_DAYS = 90;

    /** …and the urgent end of it, where a plan has to become an action. */
    public const EXPIRY_URGENT_DAYS = 30;

    /** One shelf at a time, when a pharmacy runs several hundred lines. */
    #[Url(history: true, except: '')]
    public string $category = '';

    // ── The monthly sweep ────────────────────────────────────────────────
    /** @var list<int> the expired lines picked for one write-off */
    public array $sweeping = [];

    public bool $showSweep = false;

    public string $sweepReason = '';

    public ?string $sweepNote = null;

    public function mount(): void
    {
        $this->authorize('viewAny', StockItem::class);
    }

    /**
     * Everything this board looks at: stocked, and on the chosen shelf.
     *
     * @return Builder<StockItem>
     */
    private function onShelf(): Builder
    {
        return StockItem::with('category')
            ->where('is_active', true)
            ->when($this->category !== '', fn (Builder $q) => $q->where('stock_category_id', (int) $this->category));
    }

    /**
     * Nothing left at all — not a warning, a stoppage.
     *
     * @return Collection<int,StockItem>
     */
    #[Computed]
    public function outOfStock(): Collection
    {
        return $this->onShelf()->where('current_quantity', '<=', 0)->orderBy('name')->get();
    }

    /**
     * Below the reorder level, but not empty: there is still time to order.
     *
     * @return Collection<int,StockItem>
     */
    #[Computed]
    public function lowStock(): Collection
    {
        $query = $this->onShelf()->where('current_quantity', '>', 0);

        return StockItem::applyLowStock($query)->orderBy('name')->get();
    }

    /**
     * Expiring soon, but NOT yet expired — stock there is still time to use.
     *
     * @return Collection<int,StockItem>
     */
    #[Computed]
    public function expiring(): Collection
    {
        $query = $this->onShelf()
            ->where('current_quantity', '>', 0)
            ->whereDate('expiry_date', '>', now()->toDateString());

        return StockItem::applyExpiringBefore($query, now()->addDays(self::EXPIRY_HORIZON_DAYS)->toDateString())
            ->orderBy('expiry_date')
            ->get();
    }

    /**
     * Already expired, and still on the books.
     *
     * @return Collection<int,StockItem>
     */
    #[Computed]
    public function expired(): Collection
    {
        $query = $this->onShelf()->where('current_quantity', '>', 0);

        return StockItem::applyExpiringBefore($query, now()->toDateString())
            ->orderBy('expiry_date')
            ->get();
    }

    /**
     * What is at risk, and the two figures that make anybody act: what the
     * expired stock is WORTH, and what it would cost to put the shortages
     * right.
     *
     * @return array{out:int,low:int,expiring:int,expired:int,value:string,shortfall:string}
     */
    #[Computed]
    public function atRisk(): array
    {
        $shortfall = '0';

        foreach ($this->outOfStock->concat($this->lowStock) as $item) {
            $short = bcsub((string) $item->reorder_level, (string) $item->current_quantity, 2);

            if (bccomp($short, '0', 2) > 0) {
                $shortfall = bcadd($shortfall, bcmul($short, (string) $item->cost_price, 2), 2);
            }
        }

        return [
            'out' => $this->outOfStock->count(),
            'low' => $this->lowStock->count(),
            'expiring' => $this->expiring->count(),
            'expired' => $this->expired->count(),
            'value' => (string) $this->expired->sum(fn (StockItem $i) => (float) $i->current_stock_value),
            'shortfall' => $shortfall,
        ];
    }

    /** How many days until it goes off — negative once it has. */
    public function daysLeft(StockItem $item): ?int
    {
        return $item->expiry_date === null
            ? null
            : (int) now()->startOfDay()->diffInDays($item->expiry_date->startOfDay(), false);
    }

    /** '' or 'warn' — whether an expiry has stopped being a plan. */
    public function expiryTone(StockItem $item): string
    {
        $days = $this->daysLeft($item);

        return $days !== null && $days <= self::EXPIRY_URGENT_DAYS ? 'warn' : '';
    }

    /** How much would bring this line back up to its reorder level. */
    public function shortBy(StockItem $item): string
    {
        $short = bcsub((string) $item->reorder_level, (string) $item->current_quantity, 2);

        return bccomp($short, '0', 2) > 0 ? $short : '0';
    }

    /** What it would cost to order that much. */
    public function shortfallValue(StockItem $item): string
    {
        return bcmul($this->shortBy($item), (string) $item->cost_price, 2);
    }

    /** The shelves, for the filter. @return Collection<int,StockCategory> */
    #[Computed]
    public function categories(): Collection
    {
        return StockCategory::where('is_active', true)->orderBy('name')->get();
    }

    /** The shelf the board is pinned to, so the page can name it. */
    #[Computed]
    public function pinnedCategory(): ?StockCategory
    {
        return $this->category === '' ? null : StockCategory::find((int) $this->category);
    }

    public function clearFilters(): void
    {
        $this->category = '';
        $this->forgetBoard();
    }

    /** The board is what changed. */
    protected function afterStockMoved(): void
    {
        $this->forgetBoard();
    }

    /** A dialog should not land on top of the one that opened it. */
    protected function beforeActingOnStock(): void
    {
        $this->showSweep = false;
        $this->closePeek();
    }

    private function forgetBoard(): void
    {
        unset($this->outOfStock, $this->lowStock, $this->expiring, $this->expired, $this->atRisk);
    }

    // ── Writing off a month of expiries in one go ────────────────────────

    /**
     * A month's expiries is a sweep, not eleven separate decisions.
     *
     * Every line still becomes its OWN ledger movement with its own quantity
     * and balance — the ledger is not batched, only the asking is. One of them
     * failing must not take the others with it, so each is posted on its own
     * and the ones that could not be are named.
     */
    public function toggleSweep(int $id): void
    {
        $this->sweeping = in_array($id, $this->sweeping, true)
            ? array_values(array_diff($this->sweeping, [$id]))
            : [...$this->sweeping, $id];
    }

    public function sweepAll(): void
    {
        $this->sweeping = $this->expired->pluck('id')->all();
    }

    public function openSweep(): void
    {
        $this->authorize('update', $this->expired->first() ?? new StockItem);

        if ($this->sweeping === []) {
            return;
        }

        $this->sweepReason = StockMovementReason::Expired->value;
        $this->sweepNote = 'Expiry sweep '.now()->format('F Y');
        $this->resetErrorBag();
        $this->showSweep = true;
    }

    public function closeSweep(): void
    {
        $this->reset(['showSweep', 'sweepReason', 'sweepNote']);
        $this->resetErrorBag();
    }

    /** What the sweep is about to take off the books. */
    public function sweepValue(): string
    {
        $total = '0';

        foreach ($this->expired as $item) {
            if (in_array($item->id, $this->sweeping, true)) {
                $total = bcadd($total, (string) $item->current_stock_value, 2);
            }
        }

        return $total;
    }

    public function runSweep(StockService $stock): void
    {
        if (! $this->showSweep || $this->sweeping === []) {
            return;
        }

        $data = $this->validate([
            'sweepReason' => ['required', Rule::in(array_map(
                fn (StockMovementReason $r) => $r->value,
                StockMovementReason::losses(),
            ))],
            'sweepNote' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'sweepNote.required' => 'Say why these are being written off — this is the record somebody audits.',
        ], ['sweepReason' => 'reason', 'sweepNote' => 'reason in words']);

        $reason = StockMovementReason::from($data['sweepReason']);
        $done = 0;
        $refused = [];

        foreach ($this->expired as $item) {
            if (! in_array($item->id, $this->sweeping, true)) {
                continue;
            }

            $this->authorize('update', $item);

            try {
                // One movement per line, each with its own quantity and its own
                // balance after. A single lumped row would be untraceable.
                $stock->adjust($item, $reason, (string) $item->current_quantity, Auth::id(), $data['sweepNote']);
                $done++;
            } catch (RuntimeException) {
                // Something moved underneath us — dispensed while the dialog
                // was open. Say which, rather than failing all of them.
                $refused[] = $item->name;
            }
        }

        $value = $this->sweepValue();

        $this->sweeping = [];
        $this->closeSweep();
        $this->forgetBoard();

        $this->dispatch('toast', type: $refused === [] ? 'success' : 'error', message: $refused === []
            ? $done.' '.\Illuminate\Support\Str::plural('line', $done).' written off — '
                .HospitalSettings::money($value).' off the books.'
            : $done.' written off; could not write off '.implode(', ', $refused).'.');
    }

    /** @return array<string,string> */
    public function sweepReasons(): array
    {
        return $this->lossReasons();
    }

    public function render()
    {
        $this->authorize('viewAny', StockItem::class);

        return view('livewire.stock.alerts', [
            'horizonDays' => self::EXPIRY_HORIZON_DAYS,
        ])->title('Stock alerts');
    }
}
