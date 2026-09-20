<?php

namespace App\Livewire\Concerns;

use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * One stock item, read over whatever list you came from.
 *
 * The row shows what fits; the record has more, and the useful part is the
 * part that does not fit — HOW IT GOT TO THIS NUMBER. A storekeeper asking
 * "why is there only twelve left" was two navigations away from the answer and
 * had lost their place in the list by the time they found it.
 *
 * Held once so the stock list and the alerts board cannot come to show
 * different things about the same item.
 */
trait PeeksStockItems
{
    public bool $showPeek = false;

    public ?int $peekId = null;

    /** How many ledger rows the dialog shows before deferring to the ledger. */
    public const PEEK_HISTORY = 6;

    public function peek(int $id): void
    {
        $item = StockItem::findOrFail($id);
        $this->authorize('view', $item);

        $this->peekId = $item->id;
        unset($this->peeked, $this->peekedHistory);
        $this->showPeek = true;
    }

    public function closePeek(): void
    {
        $this->reset(['showPeek', 'peekId']);
    }

    #[Computed]
    public function peeked(): ?StockItem
    {
        return $this->peekId === null ? null : StockItem::with('category')->find($this->peekId);
    }

    /**
     * The last few things that happened to it.
     *
     * Capped: the whole story is the ledger, one click away, and a dialog that
     * loads five hundred rows to show six is a dialog that opens slowly.
     *
     * @return Collection<int,StockMovement>
     */
    #[Computed]
    public function peekedHistory(): Collection
    {
        return $this->peekId === null
            ? collect()
            : StockMovement::with('createdBy')
                ->where('stock_item_id', $this->peekId)
                ->latest('id')
                ->limit(self::PEEK_HISTORY)
                ->get();
    }
}
