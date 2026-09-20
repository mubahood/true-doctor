<?php

namespace App\Services;

use App\Enums\StockMovementReason;
use App\Exceptions\InsufficientStockException;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of stock quantities. Every movement runs in a transaction with
 * a locking read on the item (no lost updates on concurrent receive/dispense),
 * appends an immutable ledger row with a balance snapshot, and re-derives
 * current_stock_value = current_quantity × cost_price. Outgoing movements that
 * would drive stock negative are rejected (InsufficientStockException). All
 * quantity/value math is bcmath scale-2 — no float drift, no boot-hook recalc.
 */
class StockService
{
    /**
     * Put stock on the shelf, and record what it cost and what it will sell for.
     *
     * Both prices are the store's answer to the only two questions it is
     * judged on. A delivery that changes either changes it on the item too, so
     * the next dispensation is priced from what was actually paid — and every
     * movement keeps the pair it happened under, so a repricing today cannot
     * rewrite what last month's sales looked like.
     */
    public function receive(StockItem $item, string $quantity, ?string $unitCost = null, ?int $by = null, ?string $note = null, ?string $salePrice = null): StockMovement
    {
        return $this->post($item, StockMovementReason::Received, $quantity, $unitCost, $by, $note, null, $salePrice);
    }

    public function dispenseOut(StockItem $item, string $quantity, array $opts = [], ?int $by = null): StockMovement
    {
        return $this->post($item, StockMovementReason::Dispensed, $quantity, null, $by, $opts['note'] ?? null, $opts['source'] ?? null);
    }

    public function adjust(StockItem $item, StockMovementReason $reason, string $quantity, ?int $by = null, ?string $note = null): StockMovement
    {
        return $this->post($item, $reason, $quantity, null, $by, $note);
    }

    /**
     * Put back whatever a source took off the shelf.
     *
     * The ledger is the authority, not the thing being undone: this returns
     * exactly what was recorded as going out for that source, so it stays
     * right even where a line and its movements could disagree. Nothing went
     * out, nothing comes back.
     *
     * @return list<StockMovement> the returns posted, one per original movement
     */
    public function returnFor(object $source, ?int $by = null, ?string $note = null): array
    {
        $returns = [];

        foreach ($this->stillOutFor($source) as $stockItemId => $owed) {
            /** @var StockItem|null $item */
            $item = StockItem::find($stockItemId);
            if ($item === null) {
                continue;
            }

            $returns[] = $this->post($item, StockMovementReason::ReturnedToStock,
                $owed, null, $by, $note, $source);
        }

        return $returns;
    }

    /**
     * Put back SOME of what a source took, rather than all of it.
     *
     * Used when a line's quantity is lowered. It is capped at what that source
     * still has out, so a correction can never return more than was taken.
     */
    public function returnQuantity(StockItem $item, string $quantity, object $source, ?int $by = null, ?string $note = null): ?StockMovement
    {
        $owed = $this->stillOutFor($source)[$item->id] ?? '0.00';
        $give = bccomp($quantity, $owed, 2) > 0 ? $owed : $quantity;

        if (bccomp($give, '0', 2) <= 0) {
            return null;
        }

        return $this->post($item, StockMovementReason::ReturnedToStock, $give, null, $by, $note, $source);
    }

    /**
     * How much a source currently has off the shelf, per item.
     *
     * The ledger is the authority. Anything that wants to know whether a
     * charge and the shelf agree asks this rather than counting movements,
     * because a line's quantity can have been corrected since.
     *
     * @return array<int, string> stock item id => quantity still out
     */
    public function outstandingFor(object $source): array
    {
        return $this->stillOutFor($source);
    }

    /**
     * What a source still has off the shelf: taken, less already put back.
     *
     * A balance rather than a flag. It used to ask "has anything been returned
     * for this source?" and stop if so, which was right only while a line's
     * quantity could never change — the moment one could be reduced, the
     * partial return that followed made a later cancellation skip the rest and
     * leave the remainder off the shelf for good.
     *
     * @return array<int, string> stock item id => quantity still out
     */
    private function stillOutFor(object $source): array
    {
        $movements = StockMovement::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->whereIn('reason', [
                StockMovementReason::Dispensed->value,
                StockMovementReason::ReturnedToStock->value,
            ])
            ->get();

        $balances = [];
        foreach ($movements as $movement) {
            $id = (int) $movement->stock_item_id;
            $balances[$id] ??= '0.00';
            $balances[$id] = $movement->reason === StockMovementReason::Dispensed
                ? bcadd($balances[$id], (string) $movement->quantity, 2)
                : bcsub($balances[$id], (string) $movement->quantity, 2);
        }

        return array_filter($balances, fn (string $owed) => bccomp($owed, '0', 2) > 0);
    }

    /**
     * What returnFor() would put back, without putting it back.
     *
     * The cancel confirmation shows this before anything is undone, so nobody
     * discovers what a cancellation costs by performing it.
     *
     * @return list<array{stock_item_id:int,name:string,quantity:string,unit:string}>
     */
    public function pendingReturnFor(object $source): array
    {
        $out = [];

        foreach ($this->stillOutFor($source) as $stockItemId => $owed) {
            // The ledger outlives the shelf: a hard-deleted item leaves its
            // movements behind, and they still have to read.
            $item = StockItem::find($stockItemId);

            $out[] = [
                'stock_item_id' => $stockItemId,
                'name' => $item instanceof StockItem ? (string) $item->name : 'Stock item',
                'quantity' => rtrim(rtrim($owed, '0'), '.'),
                'unit' => $item instanceof StockItem ? trim((string) $item->unit) : '',
            ];
        }

        return $out;
    }

    private function post(StockItem $item, StockMovementReason $reason, string $quantity, ?string $unitCost, ?int $by, ?string $note, ?object $source = null, ?string $salePrice = null): StockMovement
    {
        if (bccomp($quantity, '0', 2) <= 0) {
            throw new \RuntimeException('Quantity must be greater than zero.');
        }

        return DB::transaction(function () use ($item, $reason, $quantity, $unitCost, $by, $note, $source, $salePrice) {
            /** @var StockItem $locked */
            $locked = StockItem::whereKey($item->id)->lockForUpdate()->firstOrFail();

            $oldQty = (string) $locked->current_quantity;
            $delta = $reason->sign() === 1 ? $quantity : bcmul($quantity, '-1', 2);
            $newQty = bcadd($oldQty, $delta, 2);

            if (bccomp($newQty, '0', 2) < 0) {
                throw InsufficientStockException::make($locked->name, (string) $locked->current_quantity, $quantity);
            }

            if ($unitCost !== null && $reason->isIncoming()) {
                $locked->cost_price = $unitCost;
            }
            if ($salePrice !== null && $reason->isIncoming()) {
                $locked->sale_price = $salePrice;
            }
            $locked->current_quantity = $newQty;
            $locked->current_stock_value = bcmul($newQty, (string) $locked->cost_price, 2);
            $locked->save();

            $movement = StockMovement::create([
                'stock_item_id' => $locked->id,
                'reason' => $reason,
                'quantity' => $quantity,
                'balance_after' => $newQty,
                // BOTH prices, on EVERY row, as they stood at this moment.
                // The ledger used to keep the cost only on the way in, so a
                // price change last week made every sale before it look as
                // though it had been sold at today's price.
                'unit_cost' => $unitCost ?? (string) $locked->cost_price,
                'unit_price' => $salePrice ?? (string) $locked->sale_price,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'note' => $note,
                'created_by' => $by,
                'created_at' => Carbon::now(),
            ]);

            // Alert once, only when this movement crosses into low-stock territory.
            $reorder = (string) $locked->reorder_level;
            if (! $reason->isIncoming()
                && bccomp($newQty, $reorder, 2) <= 0
                && bccomp($oldQty, $reorder, 2) > 0) {
                $this->notifyLowStock($locked);
            }

            return $movement;
        });
    }

    private function notifyLowStock(StockItem $item): void
    {
        $recipients = \App\Models\User::where('hospital_id', $item->hospital_id)
            ->whereIn('role', ['pharmacist', 'hospital_admin'])
            ->get();

        if ($recipients->isNotEmpty()) {
            \Illuminate\Support\Facades\Notification::send($recipients, new \App\Notifications\LowStockAlert($item));
        }
    }
}
