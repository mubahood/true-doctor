<?php

namespace App\Livewire\Stock;

use App\Enums\StockMovementReason;
use App\Livewire\Concerns\WithTable;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Services\StockLedger;
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
        return StockLedger::totals($this->filtered());
    }

    /** @return Builder<StockMovement> */
    private function filtered(): Builder
    {
        return StockLedger::filtered([
            'item' => $this->item, 'reason' => $this->reason, 'direction' => $this->direction,
            'from' => $this->from, 'to' => $this->to, 'search' => $this->search,
        ]);
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
