<?php

namespace App\Livewire\Stock;

use App\Enums\StockMovementReason;
use App\Livewire\Concerns\MovesStock;
use App\Livewire\Concerns\PeeksStockItems;
use App\Models\StockCategory;
use App\Models\StockItem;
use App\Services\StockAlertBoard;
use App\Services\StockService;
use App\Support\HospitalSettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

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
    public const EXPIRY_HORIZON_DAYS = StockAlertBoard::EXPIRY_HORIZON_DAYS;

    /** …and the urgent end of it, where a plan has to become an action. */
    public const EXPIRY_URGENT_DAYS = StockAlertBoard::EXPIRY_URGENT_DAYS;

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

    private function board(): StockAlertBoard
    {
        return $this->board ??= StockAlertBoard::forCategory($this->category);
    }

    private ?StockAlertBoard $board = null;

    /** @return Collection<int,StockItem> */
    #[Computed]
    public function outOfStock(): Collection
    {
        return $this->board()->outOfStock();
    }

    /** @return Collection<int,StockItem> */
    #[Computed]
    public function lowStock(): Collection
    {
        return $this->board()->lowStock();
    }

    /** @return Collection<int,StockItem> */
    #[Computed]
    public function expiring(): Collection
    {
        return $this->board()->expiring();
    }

    /** @return Collection<int,StockItem> */
    #[Computed]
    public function expired(): Collection
    {
        return $this->board()->expired();
    }

    /** @return array{out:int,low:int,expiring:int,expired:int,value:string,shortfall:string} */
    #[Computed]
    public function atRisk(): array
    {
        return $this->board()->atRisk();
    }

    /** How many days until it goes off - negative once it has. */
    public function daysLeft(StockItem $item): ?int
    {
        return StockAlertBoard::daysLeft($item);
    }

    /** '' or 'warn' - whether an expiry has stopped being a plan. */
    public function expiryTone(StockItem $item): string
    {
        return StockAlertBoard::expiryTone($item);
    }

    /** How much would bring this line back up to its reorder level. */
    public function shortBy(StockItem $item): string
    {
        return StockAlertBoard::shortBy($item);
    }

    /** What it would cost to order that much. */
    public function shortfallValue(StockItem $item): string
    {
        return StockAlertBoard::shortfallValue($item);
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
        $this->board = null;
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

        $picked = $this->expired->filter(fn (StockItem $item) => in_array($item->id, $this->sweeping, true));
        foreach ($picked as $item) {
            $this->authorize('update', $item);
        }

        // One movement per line, each with its own quantity and balance after;
        // the ones that could not be written off are named.
        $result = $this->board()->sweep($picked, $reason, $data['sweepNote'], Auth::id(), $stock);
        $done = $result['done'];
        $refused = $result['refused'];

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
