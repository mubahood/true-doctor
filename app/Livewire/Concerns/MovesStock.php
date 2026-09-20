<?php

namespace App\Livewire\Concerns;

use App\Enums\StockMovementReason;
use App\Http\Requests\StockAdjustRequest;
use App\Http\Requests\StockReceiveRequest;
use App\Models\StockItem;
use App\Services\StockService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use RuntimeException;

/**
 * Moving stock on and off the shelf, from whatever screen is showing it.
 *
 * There is already a ledger: StockMovement is append-only, every row carries
 * the balance AFTER it, StockService is the only thing that writes one, and it
 * takes a row lock and refuses to drive a shelf negative. Dispensing, returns
 * and order corrections all post to it. So "records of how each item went or
 * came in" did not need building — it needed SHOWING, and it needed to be
 * reachable from the screens where somebody notices the problem.
 *
 * Which is what this is: receive, adjust and write off, from the stock list,
 * from an alert, from anywhere. Held once so three screens cannot come to
 * disagree about what a write-off is.
 */
trait MovesStock
{
    public bool $showMove = false;

    public ?int $movingId = null;

    /** 'receive' | 'adjust' | 'writeoff' — what is being done to the shelf. */
    public string $moveKind = 'receive';

    public string $quantity = '';

    public ?string $unit_cost = null;

    /**
     * What the whole delivery cost.
     *
     * The invoice from a supplier says "20 boxes, 240,000" — it does not say
     * 12,000 each, and asking a storekeeper to divide before they can type is
     * asking them to make an arithmetic mistake on the record. Either one
     * fills the other in; whichever was typed last is the one believed.
     */
    public ?string $total_cost = null;

    /**
     * What one unit will be SOLD for.
     *
     * `move_sale_price`, not `sale_price`: the stock list also carries a form
     * for EDITING an item, which owns that name, and two things called the
     * same thing on one component is how a delivery quietly rewrites the
     * details of the item it was delivered against.
     */
    public ?string $move_sale_price = null;

    public ?string $note = null;

    /** Only used by an adjustment; a write-off's reason is fixed. */
    public string $reason = '';

    /** Whatever the host caches about the rows it draws. */
    abstract protected function afterStockMoved(): void;

    #[Computed]
    public function moving(): ?StockItem
    {
        return $this->movingId === null ? null : StockItem::with('category')->find($this->movingId);
    }

    public function openReceive(int $id): void
    {
        $this->startMove($id, 'receive');

        // Opened at what the item is priced at now, so a delivery at the same
        // prices is one number to type rather than three.
        $item = $this->moving;

        if ($item !== null) {
            $this->unit_cost = self::trim((string) $item->cost_price);
            $this->move_sale_price = self::trim((string) $item->sale_price);
        }
    }

    /** Typing the total works out the unit, and vice versa. */
    public function updatedTotalCost(): void
    {
        $quantity = (float) $this->quantity;

        if ($quantity <= 0 || ! is_numeric($this->total_cost)) {
            return;
        }

        $this->unit_cost = self::trim(bcdiv((string) $this->total_cost, (string) $quantity, 2));
    }

    public function updatedUnitCost(): void
    {
        $this->recalcTotal();
    }

    public function updatedQuantity(): void
    {
        $this->recalcTotal();
    }

    private function recalcTotal(): void
    {
        if (! is_numeric($this->quantity) || ! is_numeric($this->unit_cost)) {
            return;
        }

        $this->total_cost = self::trim(bcmul((string) $this->quantity, (string) $this->unit_cost, 2));
    }

    /**
     * What the store stands to make on this delivery, if it all sells.
     *
     * Shown while it is being typed, because a price entered the wrong way
     * round — selling below cost — is a mistake nobody notices from two
     * numbers side by side, and obvious from one that has gone negative.
     *
     * @return array{cost:string,sale:string,margin:string,percent:string|null}|null
     */
    public function marginPreview(): ?array
    {
        if (! is_numeric($this->quantity) || ! is_numeric($this->unit_cost) || ! is_numeric($this->move_sale_price)) {
            return null;
        }

        $cost = bcmul((string) $this->quantity, (string) $this->unit_cost, 2);
        $sale = bcmul((string) $this->quantity, (string) $this->move_sale_price, 2);
        $margin = bcsub($sale, $cost, 2);

        return [
            'cost' => $cost,
            'sale' => $sale,
            'margin' => $margin,
            'percent' => bccomp($cost, '0', 2) > 0
                ? bcmul(bcdiv($margin, $cost, 4), '100', 1)
                : null,
        ];
    }

    /** Drop the trailing zeros a form should not be showing anybody. */
    private static function trim(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.') ?: '0';
    }

    public function openAdjust(int $id): void
    {
        $this->startMove($id, 'adjust');
        $this->reason = StockMovementReason::AdjustmentOut->value;
    }

    /**
     * Write off what is on the shelf and say why.
     *
     * The commonest reason by far is that it expired, so the quantity starts
     * at everything that is left — writing off half a shelf of expired stock
     * and leaving the rest on the books is the mistake this is here to stop.
     */
    public function openWriteOff(int $id): void
    {
        $this->startMove($id, 'writeoff');

        $item = $this->moving;

        if ($item === null) {
            return;
        }

        $this->quantity = self::trim((string) $item->current_quantity);

        // Expired is the commonest answer by a distance, and it is already
        // known — the date is on the item. Anything else is one click.
        $this->reason = $item->isExpired()
            ? StockMovementReason::Expired->value
            : StockMovementReason::Damaged->value;

        $this->note = $item->isExpired()
            ? 'Expired '.$item->expiry_date?->format('j M Y')
            : null;
    }

    /** Whatever the host has open that a dialog should not land on top of. */
    protected function beforeActingOnStock(): void
    {
        //
    }

    private function startMove(int $id, string $kind): void
    {
        $item = StockItem::findOrFail($id);
        $this->authorize('update', $item);

        $this->beforeActingOnStock();

        $this->reset(['quantity', 'unit_cost', 'total_cost', 'move_sale_price', 'note', 'reason']);
        $this->resetErrorBag();

        $this->movingId = $item->id;
        $this->moveKind = $kind;
        unset($this->moving);
        $this->showMove = true;
    }

    public function closeMove(): void
    {
        $this->reset(['showMove', 'movingId', 'moveKind', 'quantity', 'unit_cost', 'total_cost', 'move_sale_price', 'note', 'reason']);
        $this->resetErrorBag();
    }

    /** One submit for all three, because they are one act: the shelf changed. */
    public function saveMove(StockService $stock): void
    {
        $item = $this->moving;

        if (! $this->showMove || $item === null) {
            return;
        }

        $this->authorize('update', $item);

        try {
            match ($this->moveKind) {
                'receive' => $this->postReceive($stock, $item),
                'writeoff' => $this->postWriteOff($stock, $item),
                default => $this->postAdjust($stock, $item),
            };
        } catch (RuntimeException $e) {
            // InsufficientStockException among them: the movement would drive
            // the shelf negative, and a shelf cannot hold less than nothing.
            $this->addError('quantity', $e->getMessage());

            return;
        }

        $moved = $this->moveKind;

        $this->closeMove();
        $this->afterStockMoved();

        $this->dispatch('toast', type: 'success', message: match ($moved) {
            'receive' => 'Stock received.',
            'writeoff' => 'Written off, and the reason is on the record.',
            default => 'Adjustment recorded.',
        });
    }

    private function postReceive(StockService $stock, StockItem $item): void
    {
        $data = $this->validate(array_merge(StockReceiveRequest::rulesFor(), [
            'total_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'move_sale_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
        ]), [], ['move_sale_price' => 'selling price']);

        $stock->receive(
            $item,
            self::amount($data['quantity']),
            filled($data['unit_cost'] ?? null) ? self::amount($data['unit_cost']) : null,
            Auth::id(),
            ($data['note'] ?? null) ?: null,
            filled($data['move_sale_price'] ?? null) ? self::amount($data['move_sale_price']) : null,
        );
    }

    private function postAdjust(StockService $stock, StockItem $item): void
    {
        $data = $this->validate(StockAdjustRequest::rulesFor());

        $stock->adjust(
            $item,
            StockMovementReason::from($data['reason']),
            self::amount($data['quantity']),
            Auth::id(),
            ($data['note'] ?? null) ?: null,
        );
    }

    /**
     * A write-off is an adjustment with its reason already decided, and a
     * reason in words is REQUIRED: "wastage, 400 tablets" with no explanation
     * is the entry an auditor stops at.
     */
    /**
     * A write-off is a loss with its cause named.
     *
     * The cause is a REASON on the ledger, not a sentence in a note: expired,
     * damaged and stolen are three different problems with three different
     * fixes, and a store that files them all as "wastage" cannot tell which
     * one it has. The note is still required on top, because "expired, 400
     * tablets" is the entry an auditor asks a follow-up question about.
     */
    private function postWriteOff(StockService $stock, StockItem $item): void
    {
        $data = $this->validate([
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'reason' => ['required', Rule::in(array_map(
                fn (StockMovementReason $r) => $r->value,
                StockMovementReason::losses(),
            ))],
            'note' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'note.required' => 'Say why it is being written off — this is the record somebody audits.',
            'reason.required' => 'Choose what happened to it.',
        ]);

        $stock->adjust(
            $item,
            StockMovementReason::from($data['reason']),
            self::amount($data['quantity']),
            Auth::id(),
            $data['note'],
        );
    }

    /** @return array<string,string> what a write-off may be blamed on */
    public function lossReasons(): array
    {
        $options = [];

        foreach (StockMovementReason::losses() as $reason) {
            $options[$reason->value] = $reason->label();
        }

        return $options;
    }

    /**
     * The reasons an adjustment may carry — a correction either way, plus the
     * losses, so a storekeeper reaching for "adjust" can still say what
     * actually happened rather than filing it as a bare number.
     *
     * @return array<string,string>
     */
    public function adjustReasons(): array
    {
        $options = [];

        foreach (StockAdjustRequest::reasons() as $reason) {
            $options[$reason->value] = $reason->label();
        }

        return $options;
    }

    /** Normalise a typed amount to two decimal places, as the ledger stores it. */
    private static function amount(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
