<?php

namespace App\Livewire\Stock;

use App\Enums\StockMovementReason;
use App\Livewire\Concerns\WithTable;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The store's record book: everything that came in, everything that went out.
 *
 * The ledger has always been there — StockMovement is append-only, every row
 * carries the balance AFTER it, and StockService is the only thing that writes
 * one — but it could only be read ONE ITEM AT A TIME, on that item's own page.
 * So "what left the store last month, and why" was a question the system held
 * the answer to and could not be asked.
 *
 * Every row says what moved, which way, how much, what the shelf stood at
 * afterwards, who did it and why. Where a movement came from a piece of work
 * rather than a person — a dispensation, an order line — it says that too, and
 * links to it.
 *
 * @property-read array{in:string,out:string,rows:int,bought:string,sold:string,margin:string,lost:string,unpriced:int} $totals
 */
#[Layout('layouts.admin')]
class Movements extends Component
{
    use AuthorizesRequests, WithTable;

    /** '' = both ways; 'in' or 'out'. */
    #[Url(history: true, except: '')]
    public string $direction = '';

    #[Url(history: true, except: '')]
    public string $reason = '';

    /** One item's whole story, when you arrive from its page. */
    #[Url(history: true, except: '')]
    public string $item = '';

    #[Url(history: true, except: '')]
    public string $from = '';

    #[Url(history: true, except: '')]
    public string $to = '';

    public function mount(): void
    {
        $this->authorize('viewAny', StockItem::class);
    }

    protected function resetsPage(): array
    {
        return ['direction', 'reason', 'item', 'from', 'to'];
    }

    /** @return list<string> */
    protected function sortableFields(): array
    {
        return ['created_at', 'quantity', 'reason'];
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->direction !== '' || $this->reason !== ''
            || $this->item !== '' || $this->from !== '' || $this->to !== '';
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'direction', 'reason', 'item', 'from', 'to']);
        $this->resetPage();
    }

    /** @return array<string,string> */
    public function reasons(): array
    {
        return StockMovementReason::options();
    }

    /** The item the ledger is pinned to, so the page can name it. */
    #[Computed]
    public function pinnedItem(): ?StockItem
    {
        return $this->item === '' ? null : StockItem::find((int) $this->item);
    }

    /**
     * What the shown period moved, both ways.
     *
     * Summed over the FILTERED set rather than everything, because the figure
     * is only meaningful beside the rows it belongs to: "1,200 out" means
     * nothing without knowing out of what, and when.
     *
     * @return array{in:string,out:string,rows:int,bought:string,sold:string,margin:string,lost:string,unpriced:int}
     */
    #[Computed]
    public function totals(): array
    {
        $incoming = array_map(
            fn (StockMovementReason $r) => $r->value,
            array_filter(StockMovementReason::cases(), fn (StockMovementReason $r) => $r->isIncoming()),
        );

        $base = $this->filtered();

        $losses = array_map(
            fn (StockMovementReason $r) => $r->value,
            StockMovementReason::losses(),
        );

        // Money is summed in PHP over the rows, not in SQL: a movement's worth
        // is quantity × the price IT happened under, and the database has no
        // column holding that product. Bounded by the page's own filters, and
        // the figures are only ever read beside them.
        $priced = (clone $base)->select(['reason', 'quantity', 'unit_cost', 'unit_price'])->get();

        $bought = '0';
        $sold = '0';
        $margin = '0';
        $lost = '0';
        $unpriced = 0;

        foreach ($priced as $movement) {
            $cost = $movement->costValue();

            if ($cost === null) {
                $unpriced++;

                continue;
            }

            if ($movement->reason->isIncoming()) {
                $bought = bcadd($bought, $cost, 2);

                continue;
            }

            if ($movement->reason->isLoss()) {
                // A write-off is a loss at COST — what the store paid for
                // something it never sold. Valuing it at the selling price
                // would book a profit the hospital never made.
                $lost = bcadd($lost, $cost, 2);

                continue;
            }

            $sale = $movement->saleValue();

            if ($sale === null) {
                $unpriced++;

                continue;
            }

            $sold = bcadd($sold, $sale, 2);
            $margin = bcadd($margin, bcsub($sale, $cost, 2), 2);
        }

        return [
            'in' => (string) ((clone $base)->whereIn('reason', $incoming)->sum('quantity') ?: '0'),
            'out' => (string) ((clone $base)->whereNotIn('reason', $incoming)->sum('quantity') ?: '0'),
            'rows' => $priced->count(),
            'bought' => $bought,
            'sold' => $sold,
            'margin' => $margin,
            'lost' => $lost,
            'unpriced' => $unpriced,
        ];
    }

    /** @return Builder<StockMovement> */
    private function filtered(): Builder
    {
        $incoming = array_map(
            fn (StockMovementReason $r) => $r->value,
            array_filter(StockMovementReason::cases(), fn (StockMovementReason $r) => $r->isIncoming()),
        );

        return StockMovement::query()
            ->when($this->item !== '', fn (Builder $q) => $q->where('stock_item_id', (int) $this->item))
            ->when($this->reason !== '', fn (Builder $q) => $q->where('reason', $this->reason))
            ->when($this->direction === 'in', fn (Builder $q) => $q->whereIn('reason', $incoming))
            ->when($this->direction === 'out', fn (Builder $q) => $q->whereNotIn('reason', $incoming))
            ->when($this->from !== '', fn (Builder $q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to !== '', fn (Builder $q) => $q->whereDate('created_at', '<=', $this->to))
            ->when($this->search !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->whereHas('stockItem', fn (Builder $i) => $i->where('name', 'like', "%{$this->search}%"))
                ->orWhere('note', 'like', "%{$this->search}%")));
    }

    public function render()
    {
        $this->authorize('viewAny', StockItem::class);

        /** @var Builder<StockMovement> $sorted */
        $sorted = $this->applySort(
            $this->filtered()->with(['stockItem.category', 'createdBy']),
            fn (Builder $q) => $q->latest('id'),
        );

        return view('livewire.stock.movements', ['rows' => $sorted->paginate($this->perPage)])
            ->title('Stock ledger');
    }
}
