<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockItem;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Support\Sequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Buying stock: purchase orders, goods received, returns to supplier
 * (roadmap 04 §7, docs/procurement.md). The web screens and the API both call
 * this, so they cannot disagree.
 *
 * Stock still moves only through StockService. A delivery line is the source
 * of the movement that put it on the shelf, and the movement posts the books
 * (Dr inventory, Cr accounts payable) with the supplier named on the entry. A
 * return is the mirror image, valued at what that delivery cost.
 *
 * Money and quantities are bcmath strings at scale 2. Every change runs in a
 * transaction with the purchase order (or the delivery line) locked, so two
 * storekeepers receiving the same order at once cannot both take the last
 * outstanding units.
 */
class ProcurementService
{
    public function __construct(private readonly StockService $stock) {}

    // ── Orders ──────────────────────────────────────────────────────────

    /**
     * A new draft order.
     *
     * @param  array{order_date?:?string,expected_date?:?string,notes?:?string}  $data
     * @param  list<array{stock_item_id:int|string,quantity:int|float|string,unit_cost?:int|float|string|null}>  $lines
     */
    public function createOrder(Supplier $supplier, array $data, array $lines, User $by): PurchaseOrder
    {
        if (! $supplier->is_active) {
            throw ValidationException::withMessages(['supplier_id' => 'This supplier is inactive. Reactivate it before ordering from it.']);
        }

        return DB::transaction(function () use ($supplier, $data, $lines, $by) {
            $now = Carbon::now();
            $year = $now->format('Y');
            $seq = Sequence::next('purchase_order', $year, fn () => PurchaseOrder::query()->where('po_no', 'like', "PO-{$year}-%")->count());

            $order = PurchaseOrder::create([
                'uuid' => (string) Str::uuid(),
                'po_no' => 'PO-'.$year.'-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
                'supplier_id' => $supplier->id,
                'status' => PurchaseOrderStatus::Draft,
                'order_date' => $data['order_date'] ?? $now->toDateString(),
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $this->clean($data['notes'] ?? null),
                'total' => '0.00',
                'created_by' => $by->id,
            ]);

            foreach ($lines as $i => $line) {
                $this->writeLine($order, $line, "lines.{$i}");
            }
            $this->retotal($order);

            return $order->fresh(['items.stockItem', 'supplier']) ?? $order;
        });
    }

    /**
     * Change a draft's header (supplier, dates, notes). Only a draft changes;
     * an order already sent is the supplier's copy too.
     *
     * @param  array{supplier_id?:int,order_date?:?string,expected_date?:?string,notes?:?string}  $data
     */
    public function updateOrder(PurchaseOrder $order, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $data) {
            $locked = $this->lockDraft($order);

            if (isset($data['supplier_id'])) {
                $supplier = Supplier::query()->where('is_active', true)->find($data['supplier_id']);
                if ($supplier === null) {
                    throw ValidationException::withMessages(['supplier_id' => 'Choose an active supplier.']);
                }
                $locked->supplier_id = $supplier->id;
            }
            if (array_key_exists('order_date', $data) && $data['order_date']) {
                $locked->forceFill(['order_date' => $data['order_date']]);
            }
            if (array_key_exists('expected_date', $data)) {
                $locked->forceFill(['expected_date' => $data['expected_date'] ?: null]);
            }
            if (array_key_exists('notes', $data)) {
                $locked->notes = $this->clean($data['notes']);
            }
            $locked->save();

            return $locked;
        });
    }

    /**
     * Add a line to a draft. The same item twice is one line with the
     * quantities added (and the newer cost), not two lines nobody can match.
     *
     * @param  array{stock_item_id:int|string,quantity:int|float|string,unit_cost?:int|float|string|null}  $line
     */
    public function addLine(PurchaseOrder $order, array $line): PurchaseOrderItem
    {
        return DB::transaction(function () use ($order, $line) {
            $locked = $this->lockDraft($order);
            $item = $this->writeLine($locked, $line, 'line');
            $this->retotal($locked);

            return $item;
        });
    }

    public function removeLine(PurchaseOrder $order, PurchaseOrderItem $line): void
    {
        DB::transaction(function () use ($order, $line) {
            $locked = $this->lockDraft($order);
            if ((int) $line->purchase_order_id !== (int) $locked->id) {
                throw ValidationException::withMessages(['line' => 'That line is not on this order.']);
            }
            $line->delete();
            $this->retotal($locked);
        });
    }

    /** Draft → ordered: the order is sent to the supplier and can be received against. */
    public function markOrdered(PurchaseOrder $order, User $by): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $by) {
            $locked = $this->lock($order);
            $this->assertCan($locked, PurchaseOrderStatus::Ordered);

            if (! $locked->items()->exists()) {
                throw ValidationException::withMessages(['status' => 'Add at least one item before sending the order.']);
            }

            $locked->forceFill(['status' => PurchaseOrderStatus::Ordered, 'ordered_at' => now(), 'ordered_by' => $by->id])->save();

            return $locked;
        });
    }

    /** Cancel an order nothing has been received against. */
    public function cancel(PurchaseOrder $order, string $reason, User $by): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $reason, $by) {
            $locked = $this->lock($order);
            $this->assertCan($locked, PurchaseOrderStatus::Cancelled);

            if ($locked->receipts()->exists()) {
                throw ValidationException::withMessages(['status' => 'Goods have been received on this order. Close it instead.']);
            }

            return $this->end($locked, PurchaseOrderStatus::Cancelled, $reason, $by);
        });
    }

    /** Close a part-received order: the rest is not coming. What came stays. */
    public function close(PurchaseOrder $order, string $reason, User $by): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $reason, $by) {
            $locked = $this->lock($order);
            $this->assertCan($locked, PurchaseOrderStatus::Closed);

            return $this->end($locked, PurchaseOrderStatus::Closed, $reason, $by);
        });
    }

    // ── Goods received ─────────────────────────────────────────────────

    /**
     * Receive a delivery against an order (a GRN).
     *
     * Each line names the order line, how many came, and optionally the cost
     * on the supplier's invoice (else the ordered cost), the batch and the
     * expiry. Lines with nothing received are skipped; nothing may be
     * received beyond what is still outstanding. Every unit goes on the shelf
     * through StockService, which posts the books.
     *
     * @param  array{received_on?:?string,supplier_invoice_no?:?string,notes?:?string}  $data
     * @param  list<array{purchase_order_item_id:int|string,quantity:int|float|string|null,unit_cost?:int|float|string|null,batch_no?:?string,expiry_date?:?string}>  $lines
     */
    public function receive(PurchaseOrder $order, array $data, array $lines, User $by): GoodsReceipt
    {
        return DB::transaction(function () use ($order, $data, $lines, $by) {
            $locked = $this->lock($order);

            if (! $locked->status->canReceive()) {
                throw ValidationException::withMessages(['status' => 'Goods can only be received on an order that has been sent ('.$locked->status->label().' now).']);
            }

            $orderLines = $locked->items()->with('stockItem')->get()->keyBy('id');
            $accepted = [];
            $taken = [];

            foreach ($lines as $i => $line) {
                $qty = $this->amount($line['quantity'] ?? '0', "lines.{$i}.quantity");
                if (bccomp($qty, '0', 2) === 0) {
                    continue;
                }
                if (bccomp($qty, '0', 2) < 0) {
                    throw ValidationException::withMessages(["lines.{$i}.quantity" => 'A quantity cannot be negative.']);
                }

                /** @var PurchaseOrderItem|null $orderLine */
                $orderLine = $orderLines->get((int) $line['purchase_order_item_id']);
                if ($orderLine === null) {
                    throw ValidationException::withMessages(["lines.{$i}.purchase_order_item_id" => 'That line is not on this order.']);
                }
                // The same order line twice in one delivery counts once, in total.
                $taken[$orderLine->id] = bcadd($taken[$orderLine->id] ?? '0.00', $qty, 2);
                if (bccomp($taken[$orderLine->id], $orderLine->outstanding(), 2) > 0) {
                    throw ValidationException::withMessages(["lines.{$i}.quantity" => 'Only '.$this->plain($orderLine->outstanding()).' of '.($orderLine->stockItem->name ?? 'this item').' is still to come.']);
                }

                $cost = isset($line['unit_cost']) && $line['unit_cost'] !== '' ? $this->amount($line['unit_cost'], "lines.{$i}.unit_cost") : (string) $orderLine->unit_cost;
                if (bccomp($cost, '0', 2) < 0) {
                    throw ValidationException::withMessages(["lines.{$i}.unit_cost" => 'A cost cannot be negative.']);
                }

                $expiry = trim((string) ($line['expiry_date'] ?? ''));
                if ($expiry !== '' && strtotime($expiry) === false) {
                    throw ValidationException::withMessages(["lines.{$i}.expiry_date" => 'That expiry date is not a date.']);
                }

                $this->assertFits(bcmul($qty, $cost, 2), "lines.{$i}.quantity");
                $accepted[] = [$orderLine, $qty, $cost, $this->clean($line['batch_no'] ?? null, 60), $expiry === '' ? null : Carbon::parse($expiry)->toDateString()];
            }

            if ($accepted === []) {
                throw ValidationException::withMessages(['lines' => 'Enter how many of at least one item came in.']);
            }

            $receivedOn = ($data['received_on'] ?? null) ?: now()->toDateString();
            if (Carbon::parse($receivedOn)->isAfter(now()->endOfDay())) {
                throw ValidationException::withMessages(['received_on' => 'Goods cannot be received in the future.']);
            }

            $year = now()->format('Y');
            $seq = Sequence::next('goods_receipt', $year, fn () => GoodsReceipt::query()->where('grn_no', 'like', "GRN-{$year}-%")->count());

            $receipt = GoodsReceipt::create([
                'uuid' => (string) Str::uuid(),
                'grn_no' => 'GRN-'.$year.'-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
                'purchase_order_id' => $locked->id,
                'supplier_id' => $locked->supplier_id,
                'received_on' => Carbon::parse($receivedOn)->toDateString(),
                'supplier_invoice_no' => $this->clean($data['supplier_invoice_no'] ?? null, 60),
                'total' => '0.00',
                'notes' => $this->clean($data['notes'] ?? null),
                'received_by' => $by->id,
                'created_at' => now(),
            ]);

            $total = '0.00';
            foreach ($accepted as [$orderLine, $qty, $cost, $batch, $expiry]) {
                $lineTotal = bcmul($qty, $cost, 2);
                $grnLine = GoodsReceiptItem::create([
                    'goods_receipt_id' => $receipt->id,
                    'purchase_order_item_id' => $orderLine->id,
                    'stock_item_id' => $orderLine->stock_item_id,
                    'quantity' => $qty,
                    'unit_cost' => $cost,
                    'line_total' => $lineTotal,
                    'batch_no' => $batch,
                    'expiry_date' => $expiry,
                    'created_at' => now(),
                ]);

                $stockItem = StockItem::query()->find($orderLine->stock_item_id);
                if ($stockItem === null) {
                    throw ValidationException::withMessages(['lines' => 'An item on this order has been removed from the stock list. Restore it before receiving it.']);
                }
                $this->stock->receive($stockItem, $qty, $cost, $by->id, $receipt->grn_no.' from '.($locked->supplier->name ?? 'supplier'),
                    null, $grnLine, $batch, $expiry);

                $orderLine->received_quantity = bcadd((string) $orderLine->received_quantity, $qty, 2);
                $orderLine->save();
                $total = bcadd($total, $lineTotal, 2);
            }

            $receipt->forceFill(['total' => $total])->saveQuietly();

            $complete = $orderLines->every(fn (PurchaseOrderItem $l) => bccomp($l->outstanding(), '0', 2) === 0);
            $locked->forceFill(['status' => $complete ? PurchaseOrderStatus::Received : PurchaseOrderStatus::PartReceived])->save();

            return $receipt->fresh(['items.stockItem', 'supplier', 'purchaseOrder']) ?? $receipt;
        });
    }

    // ── Returns to supplier ────────────────────────────────────────────

    /**
     * Send some of a delivery line back to the supplier.
     *
     * Capped at what that line brought in less what has already gone back,
     * and at what is still on the shelf (StockService refuses a shelf below
     * zero). Valued at the delivery's cost, so the payable falls by exactly
     * what that delivery added.
     */
    public function returnToSupplier(GoodsReceiptItem $line, int|float|string $quantity, string $reason, User $by): SupplierReturn
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Say why it is going back (damaged, wrong item, short-dated…).']);
        }

        return DB::transaction(function () use ($line, $quantity, $reason, $by) {
            /** @var GoodsReceiptItem $locked */
            $locked = GoodsReceiptItem::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $qty = $this->amount($quantity, 'quantity');

            if (bccomp($qty, '0', 2) <= 0) {
                throw ValidationException::withMessages(['quantity' => 'Enter how many are going back.']);
            }
            if (bccomp($qty, $locked->returnable(), 2) > 0) {
                throw ValidationException::withMessages(['quantity' => 'Only '.$this->plain($locked->returnable()).' from this delivery can still go back.']);
            }

            /** @var StockItem $stockItem */
            $stockItem = StockItem::withTrashed()->findOrFail($locked->stock_item_id);
            if (bccomp($qty, (string) $stockItem->current_quantity, 2) > 0) {
                throw ValidationException::withMessages(['quantity' => 'Only '.$this->plain((string) $stockItem->current_quantity).' of '.$stockItem->name.' is on the shelf.']);
            }

            $receipt = $locked->receipt()->firstOrFail();
            $year = now()->format('Y');
            $seq = Sequence::next('supplier_return', $year, fn () => SupplierReturn::query()->where('return_no', 'like', "SR-{$year}-%")->count());

            $return = SupplierReturn::create([
                'uuid' => (string) Str::uuid(),
                'return_no' => 'SR-'.$year.'-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
                'supplier_id' => $receipt->supplier_id,
                'goods_receipt_id' => $receipt->id,
                'goods_receipt_item_id' => $locked->id,
                'stock_item_id' => $locked->stock_item_id,
                'quantity' => $qty,
                'unit_cost' => (string) $locked->unit_cost,
                'total' => bcmul($qty, (string) $locked->unit_cost, 2),
                'reason' => Str::limit($reason, 250, ''),
                'created_by' => $by->id,
                'created_at' => now(),
            ]);

            try {
                $this->stock->returnToSupplier($stockItem, $qty, (string) $locked->unit_cost, $return, $by->id,
                    $return->return_no.' ('.$receipt->grn_no.'): '.$return->reason);
            } catch (\App\Exceptions\InsufficientStockException $e) {
                // Something left the shelf between the check above and the lock.
                throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
            }

            return $return;
        });
    }

    // ── Reading ────────────────────────────────────────────────────────

    /**
     * An order's figures: ordered, received, still to come and returned, at cost.
     *
     * @return array{ordered:string,received:string,outstanding:string,returned:string}
     */
    public function figures(PurchaseOrder $order): array
    {
        $outstanding = '0.00';
        foreach ($order->items as $line) {
            $outstanding = bcadd($outstanding, bcmul($line->outstanding(), (string) $line->unit_cost, 2), 2);
        }

        return [
            'ordered' => (string) $order->total,
            'received' => bcadd((string) ($order->receipts()->sum('total') ?: '0'), '0', 2),
            'outstanding' => $order->status->isTerminal() ? '0.00' : $outstanding,
            'returned' => bcadd((string) ($order->returns()->sum('supplier_returns.total') ?: '0'), '0', 2),
        ];
    }

    // ── Plumbing ───────────────────────────────────────────────────────

    /**
     * @param  array{stock_item_id:int|string,quantity:int|float|string,unit_cost?:int|float|string|null}  $line
     */
    private function writeLine(PurchaseOrder $order, array $line, string $key): PurchaseOrderItem
    {
        $stockItem = StockItem::query()->find((int) $line['stock_item_id']);
        if ($stockItem === null) {
            throw ValidationException::withMessages(["{$key}.stock_item_id" => 'Choose an item from the stock list.']);
        }

        $qty = $this->amount($line['quantity'], "{$key}.quantity");
        if (bccomp($qty, '0', 2) <= 0) {
            throw ValidationException::withMessages(["{$key}.quantity" => 'Enter how many to order.']);
        }

        $cost = isset($line['unit_cost']) && $line['unit_cost'] !== '' ? $this->amount($line['unit_cost'], "{$key}.unit_cost") : (string) $stockItem->cost_price;
        if (bccomp($cost, '0', 2) < 0) {
            throw ValidationException::withMessages(["{$key}.unit_cost" => 'A cost cannot be negative.']);
        }

        $this->assertFits(bcmul($qty, $cost, 2), "{$key}.quantity");

        /** @var PurchaseOrderItem|null $existing */
        $existing = $order->items()->where('stock_item_id', $stockItem->id)->first();
        if ($existing !== null) {
            $qty = bcadd((string) $existing->quantity, $qty, 2);
            $this->assertFits(bcmul($qty, $cost, 2), "{$key}.quantity");
            $existing->forceFill(['quantity' => $qty, 'unit_cost' => $cost, 'line_total' => bcmul($qty, $cost, 2)])->save();

            return $existing;
        }

        return PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'stock_item_id' => $stockItem->id,
            'quantity' => $qty,
            'unit_cost' => $cost,
            'line_total' => bcmul($qty, $cost, 2),
            'received_quantity' => '0.00',
        ]);
    }

    private function retotal(PurchaseOrder $order): void
    {
        $total = '0.00';
        foreach ($order->items()->get() as $line) {
            $total = bcadd($total, (string) $line->line_total, 2);
        }
        $order->forceFill(['total' => $total])->save();
    }

    private function lock(PurchaseOrder $order): PurchaseOrder
    {
        /** @var PurchaseOrder $locked */
        $locked = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

        return $locked;
    }

    private function lockDraft(PurchaseOrder $order): PurchaseOrder
    {
        $locked = $this->lock($order);
        if ($locked->status !== PurchaseOrderStatus::Draft) {
            throw ValidationException::withMessages(['status' => 'Only a draft order can be changed. This one is '.strtolower($locked->status->label()).'.']);
        }

        return $locked;
    }

    private function assertCan(PurchaseOrder $order, PurchaseOrderStatus $to): void
    {
        if (! $order->status->canTransitionTo($to)) {
            throw ValidationException::withMessages(['status' => 'An order that is '.strtolower($order->status->label()).' cannot be '.strtolower($to->label()).'.']);
        }
    }

    private function end(PurchaseOrder $order, PurchaseOrderStatus $to, string $reason, User $by): PurchaseOrder
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason.']);
        }

        $order->forceFill([
            'status' => $to,
            'ended_at' => now(),
            'ended_by' => $by->id,
            'end_reason' => Str::limit($reason, 250, ''),
        ])->save();

        return $order;
    }

    /**
     * A quantity or amount as a scale-2 string. Anything that is not a plain
     * number with at most ten digits before the point and two after it is
     * refused, so nothing is silently rounded or clipped by the column.
     */
    private function amount(int|float|string|null $value, string $field): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '0.00';
        }
        if (preg_match('/^-?\d{1,10}(\.\d{1,2})?$/', $value) !== 1) {
            throw ValidationException::withMessages([$field => 'Enter a number with at most two decimal places.']);
        }

        return bcadd($value, '0', 2);
    }

    /** A line's value must fit the money columns (decimal 14,2). */
    private function assertFits(string $value, string $field): void
    {
        if (bccomp($value, '999999999999.99', 2) > 0) {
            throw ValidationException::withMessages([$field => 'That line is worth more than the system can hold. Check the quantity and cost.']);
        }
    }

    private function plain(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.') ?: '0';
    }

    private function clean(?string $value, int $max = 500): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $max, '');
    }
}
