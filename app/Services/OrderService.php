<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Dispensation;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Service;
use App\Models\StockItem;
use App\Models\Visit;
use App\Support\HospitalSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Places and moves the work — see docs/orders.md.
 *
 * Every piece of work done for a patient is an order on their visit: a
 * consultation, a lab test, a scan, drugs, a procedure, an admission. The
 * order owns its own billing lines, which is the whole point — cancel the work
 * and the charge goes with it, instead of stranding money on the bill.
 */
class OrderService
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly StockService $stock,
    ) {}

    /**
     * Raise a piece of work on a visit.
     *
     * @param  array{assigned_to?:int|null,department_id?:int|null,notes?:string|null,subject?:Model|null,status?:OrderStatus}  $opts
     */
    public function place(Visit $visit, OrderType $type, string $title, array $opts = [], ?int $by = null): Order
    {
        if (trim($title) === '') {
            throw new RuntimeException('An order needs to say what it is.');
        }

        return DB::transaction(function () use ($visit, $type, $title, $opts, $by) {
            $order = new Order([
                'uuid' => (string) Str::uuid(),
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'type' => $type,
                'status' => $opts['status'] ?? OrderStatus::Pending,
                'title' => $title,
                'notes' => $opts['notes'] ?? null,
                // Unassigned falls to the visit's doctor: someone is always
                // answerable for a piece of work, even before it is routed.
                'assigned_to' => $opts['assigned_to'] ?? $visit->doctor_user_id,
                'department_id' => $opts['department_id'] ?? $visit->department_id,
                'requested_by' => $by,
            ]);

            if (($subject = $opts['subject'] ?? null) instanceof Model) {
                $order->subject()->associate($subject);
            }

            $order->save();

            // The first piece of work starts the visit. Nobody should have to
            // remember to press "start", and a visit with orders on it that
            // still says Pending is simply wrong (docs/visits.md).
            app(VisitService::class)->reconcile($visit, $by);

            return $order;
        });
    }

    /**
     * The order a piece of work is billed through, placed if it has none.
     *
     * LabService and RadiologyService place one when the work is ordered, with
     * the lab or radiology record as its `subject`, and bill the tests onto it.
     * A bench adding what it USED puts the lines on that same order, so one
     * piece of work is one set of charges rather than two.
     *
     * It is a find-OR-place because a record made before that companion
     * existed — or by anything that skipped the service — still has to be
     * billable. An empty order costs nothing and is the honest answer to
     * "where does this charge go".
     */
    public function forSubject(Model $subject, Visit $visit, OrderType $type, string $title, ?int $by = null): Order
    {
        $existing = Order::with('items')
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->place($visit, $type, $title, ['subject' => $subject], $by);
    }

    /** Route the work to someone. */
    public function assign(Order $order, ?int $userId, ?int $departmentId = null): Order
    {
        $order->update([
            'assigned_to' => $userId,
            'department_id' => $departmentId ?? $order->department_id,
        ]);

        return $order->fresh();
    }

    /**
     * Move the work along. The legal moves come from the enum, and the
     * timestamps are stamped here so no caller has to remember them.
     */
    public function transition(Order $order, OrderStatus $to, ?int $by = null, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $to, $by, $reason) {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo($to)) {
                throw new RuntimeException("An order cannot go from {$locked->status->label()} to {$to->label()}.");
            }

            // Finished means something was done. An order completed with
            // nothing on it tells the next reader that work happened and
            // leaves no trace of what — worse than one still sitting open.
            // Cancelling is always available: an order raised by mistake
            // should be called off, not furnished with evidence first.
            if ($to === OrderStatus::Completed && ! $locked->hasEvidence()) {
                throw new RuntimeException(
                    'Nothing has been recorded on this order. Add what it used, write a report, '.
                    'or attach a result — or cancel it if it should not have been raised.'
                );
            }

            $locked->status = $to;
            match ($to) {
                OrderStatus::InProgress => $locked->started_at = now(),
                OrderStatus::Completed => $locked->completed_at = now(),
                OrderStatus::Cancelled => $locked->cancelled_at = now(),
                default => null,
            };
            if ($to === OrderStatus::Cancelled) {
                $locked->cancel_reason = $reason;
                if ($by !== null) {
                    $locked->assigned_to = $locked->assigned_to ?? $by;
                }

                // The money follows the work. This is the leak that loose
                // charges left open: cancelling a lab order used to leave its
                // charge sitting on the bill with nothing pointing at it.
                $this->billing->cancelItemsOf($locked);

                // And so do the goods. Cancelling a dispensing used to take the
                // charge off and leave the drugs gone.
                $this->returnEverythingTakenFor($locked, $by);
            }

            $locked->save();

            return $locked;
        });
    }

    /**
     * Finish the work, recording what it used.
     *
     * @param  list<array{service_id?:int|null,stock_item_id?:int|null,name?:string,unit_price?:string,quantity?:int,tax_exempt?:bool}>  $items
     */
    public function complete(Order $order, array $items = [], ?int $by = null): Order
    {
        return DB::transaction(function () use ($order, $items, $by) {
            foreach ($items as $item) {
                $this->billing->addItem($order, $item, $by);
            }

            return $this->transition($order, OrderStatus::Completed, $by);
        });
    }

    // ── What the work used ───────────────────────────────────────────────

    /**
     * Charge a price-list entry to this order.
     *
     * A service is sold and nothing else happens: no shelf is touched, so the
     * line is the whole story.
     */
    public function addServiceItem(Order $order, Service $service, string $quantity = '1', ?int $by = null): OrderItem
    {
        $this->assertItemsEditable($order);
        $qty = $this->assertQuantity($quantity);

        return $this->billing->addItem($order, [
            'service_id' => $service->id,
            'name' => $service->name,
            'unit_price' => (string) $service->price,
            'quantity' => $qty,
            'tax_exempt' => $service->tax_exempt,
        ], $by);
    }

    /**
     * Give the patient something off the pharmacy shelf, and charge for it.
     *
     * The charge and the stock movement are one transaction on purpose: a
     * product that is billed but not deducted, or deducted but not billed, is
     * the same class of bug the order model exists to end. If there is not
     * enough stock, InsufficientStockException rolls both back.
     */
    public function addProductItem(Order $order, StockItem $item, string $quantity = '1', ?int $by = null): OrderItem
    {
        $this->assertItemsEditable($order);
        $qty = $this->assertQuantity($quantity);

        return DB::transaction(function () use ($order, $item, $qty, $by) {
            $line = $this->billing->addItem($order, [
                'stock_item_id' => $item->id,
                'name' => $item->name,
                'unit_price' => (string) $item->sale_price,
                'quantity' => $qty,
            ], $by);

            $this->stock->dispenseOut($item, $qty, [
                'source' => $line,
                'note' => "Given on order #{$order->id}",
            ], $by);

            return $line;
        });
    }

    /**
     * Make an order's items say exactly what a screen says was used.
     *
     * The lines given are the WHOLE list: anything on the order that is not in
     * them comes off, which for a product means its stock goes back. Cancelled
     * lines are skipped when matching, or one that was taken off would quietly
     * return the next time the same thing was added.
     *
     * Shared by every screen that records work — a consultation's outcome, a
     * lab result, a radiology report — because "what did this use" is one
     * question however it is asked.
     *
     * @param  list<array{kind?:string,id:int,quantity?:string}>  $lines
     */
    public function syncLines(Order $order, array $lines, ?int $by = null): void
    {
        $existing = $order->items()
            ->where('status', '!=', OrderItemStatus::Cancelled->value)
            ->get();

        $keep = [];

        foreach ($lines as $line) {
            $isProduct = ($line['kind'] ?? 'service') === 'product';
            $quantity = (string) ($line['quantity'] ?? '1');
            $column = $isProduct ? 'stock_item_id' : 'service_id';

            $already = $existing->first(fn (OrderItem $item) => (int) $item->{$column} === (int) $line['id']);

            if ($already !== null) {
                if (bccomp((string) $already->quantity, $quantity, 4) !== 0) {
                    $this->updateItem($already, $quantity, $already->notes, $by);
                }
                $keep[] = $already->id;

                continue;
            }

            $keep[] = $isProduct
                ? $this->addProductItem($order, StockItem::findOrFail($line['id']), $quantity, $by)->id
                : $this->addServiceItem($order, Service::findOrFail($line['id']), $quantity, $by)->id;
        }

        foreach ($existing as $item) {
            if (! in_array($item->id, $keep, true)) {
                $this->removeItem($item, $by);
            }
        }
    }

    /**
     * Correct a line: how much of it, and the words beside it.
     *
     * On a product the quantity is not just a number — it is how much left the
     * shelf. Raising it takes more (and can be refused for want of stock);
     * lowering it puts the difference back. Both happen in the same
     * transaction as the money, so the bill and the shelf cannot disagree.
     */
    public function updateItem(OrderItem $line, string $quantity, ?string $notes, ?int $by = null): OrderItem
    {
        /** @var Order $order */
        $order = $line->order()->firstOrFail();
        $this->assertItemsEditable($order);

        if (! $line->status->isBillable()) {
            throw new RuntimeException('That line was removed — it cannot be changed.');
        }

        $qty = $this->assertQuantity($quantity);

        return DB::transaction(function () use ($line, $qty, $notes, $by) {
            $was = (string) $line->quantity;
            $delta = bcsub($qty, $was, 2);

            if ($line->stock_item_id !== null && bccomp($delta, '0', 2) !== 0) {
                /** @var StockItem $item */
                $item = StockItem::whereKey($line->stock_item_id)->firstOrFail();

                if (bccomp($delta, '0', 2) > 0) {
                    // More of it leaves the shelf. May fail for want of stock,
                    // which rolls the whole correction back.
                    $this->stock->dispenseOut($item, $delta, [
                        'source' => $line,
                        'note' => 'Line quantity raised',
                    ], $by);
                } else {
                    $this->stock->returnQuantity($item, bcmul($delta, '-1', 2), $line, $by,
                        'Line quantity lowered');
                }
            }

            return $this->billing->repriceItem($line, $qty, $notes, $by);
        });
    }

    /**
     * Take a line back off the order.
     *
     * It is cancelled rather than deleted: the bill drops it (a cancelled line
     * is not billable) and the trail keeps it, which is what an audit of a
     * patient's charges needs. Anything it took off the shelf goes back.
     */
    public function removeItem(OrderItem $line, ?int $by = null): OrderItem
    {
        /** @var Order $order */
        $order = $line->order()->firstOrFail();
        $this->assertItemsEditable($order);

        return DB::transaction(function () use ($line, $by) {
            $this->stock->returnFor($line, $by, 'Line removed from the order');

            return $this->billing->cancelLine($line);
        });
    }

    // ── What was found ───────────────────────────────────────────────────

    /** Save the report. Empty clears it, and clears the stamp with it. */
    public function saveReport(Order $order, ?string $report): Order
    {
        $body = trim((string) $report);

        $order->forceFill([
            'report' => $body === '' ? null : $body,
            'report_updated_at' => $body === '' ? null : now(),
        ])->save();

        return $order;
    }

    // ── Keeping the shelf and the bill in step ───────────────────────────

    /**
     * Check every product charged to this visit against the stock ledger.
     *
     * Stock moves when the work is done, not when the bill is paid — a drug
     * leaves the shelf when it is given to the patient. This is not a second
     * place that moves it; it is the check that the two agree, run at the
     * moments a visit is confirmed and moved on.
     *
     * It compares, per line, what the LEDGER says is still out against what
     * the LINE says was used, and posts only the difference. So it is
     * idempotent by construction: on a visit where nothing has drifted it
     * writes nothing at all, and running it twice can no more double-deduct
     * than running it once could.
     *
     * A line that was removed should have nothing out; one that stands should
     * have exactly its quantity out. Both directions are corrected.
     *
     * @return array{checked:int,fixed:int,notes:list<string>}
     */
    public function reconcileStock(Visit $visit, ?int $by = null): array
    {
        $checked = 0;
        $notes = [];

        $lines = OrderItem::query()
            ->whereNotNull('stock_item_id')
            ->whereHas('order', fn ($q) => $q->where('visit_id', $visit->id))
            ->get();

        foreach ($lines as $line) {
            /** @var StockItem|null $item */
            $item = StockItem::find($line->stock_item_id);
            if ($item === null) {
                continue;
            }

            $checked++;

            // A removed line owes nothing to the patient, so nothing should
            // still be off the shelf for it.
            $should = $line->status->isBillable()
                ? HospitalSettings::decimal((string) $line->quantity, 2)
                : '0.00';

            $out = $this->stock->outstandingFor($line)[$item->id] ?? '0.00';
            $gap = bcsub($should, $out, 2);

            if (bccomp($gap, '0', 2) === 0) {
                continue;
            }

            if (bccomp($gap, '0', 2) > 0) {
                $this->stock->dispenseOut($item, $gap, [
                    'source' => $line,
                    'note' => 'Reconciled against the bill',
                ], $by);
                $notes[] = $item->name.': '.$gap.' taken to match the charge';
            } else {
                $this->stock->returnQuantity($item, bcmul($gap, '-1', 2), $line, $by,
                    'Reconciled against the bill');
                $notes[] = $item->name.': '.bcmul($gap, '-1', 2).' put back';
            }
        }

        return ['checked' => $checked, 'fixed' => count($notes), 'notes' => $notes];
    }

    // ── What cancelling would cost ───────────────────────────────────────

    /**
     * What cancelling this order would undo, before it is undone.
     *
     * Cancelling reverses money and stock. Nobody should have to perform it to
     * find out how much of each, so the confirmation is built from this.
     *
     * @return array{lines:list<array{name:string,quantity:string,amount:string}>,stock:list<array{name:string,quantity:string,unit:string}>,total:string}
     */
    public function reversalPlan(Order $order): array
    {
        $lines = [];
        $stock = [];
        $total = '0.00';

        foreach ($order->items()->get() as $line) {
            if (! $line->status->isBillable()) {
                continue;
            }

            $lines[] = [
                'name' => (string) $line->name,
                'quantity' => $line->tidyQuantity(),
                'amount' => (string) $line->line_total,
            ];
            $total = bcadd($total, (string) $line->line_total, 2);

            foreach ($this->stock->pendingReturnFor($line) as $back) {
                $stock[] = ['name' => $back['name'], 'quantity' => $back['quantity'], 'unit' => $back['unit']];
            }
        }

        // Drugs dispensed the old way hang off the dispensation, not the line.
        $subject = $order->subject;
        if ($subject instanceof Dispensation) {
            foreach ($subject->items()->get() as $dispensed) {
                foreach ($this->stock->pendingReturnFor($dispensed) as $back) {
                    $stock[] = ['name' => $back['name'], 'quantity' => $back['quantity'], 'unit' => $back['unit']];
                }
            }
        }

        return ['lines' => $lines, 'stock' => $stock, 'total' => $total];
    }

    // ── Guards ───────────────────────────────────────────────────────────

    /**
     * An order's items stop being editable at two points, and only two.
     *
     * A cancelled order is finished with. And once the visit has a live
     * invoice, its lines have been snapshotted onto it — letting the order
     * drift afterwards would silently desync the bill from what was issued.
     */
    public function assertItemsEditable(Order $order): void
    {
        if ($order->status === OrderStatus::Cancelled) {
            throw new RuntimeException('This order was cancelled — its items cannot be changed.');
        }

        $invoiced = Invoice::where('visit_id', $order->visit_id)
            ->where('status', '!=', InvoiceStatus::Void->value)
            ->exists();

        if ($invoiced) {
            throw new RuntimeException('This visit has been invoiced — its charges can no longer be changed.');
        }
    }

    /** Whether the dialog should offer to change anything at all. */
    public function itemsEditable(Order $order): bool
    {
        try {
            $this->assertItemsEditable($order);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /** Put back everything this order took off the shelf, wherever it hangs. */
    private function returnEverythingTakenFor(Order $order, ?int $by): void
    {
        foreach ($order->items()->get() as $line) {
            $this->stock->returnFor($line, $by, 'Order cancelled');
        }

        $subject = $order->subject;
        if ($subject instanceof Dispensation) {
            foreach ($subject->items()->get() as $dispensed) {
                $this->stock->returnFor($dispensed, $by, 'Dispensing cancelled');
            }
        }
    }

    /** Quantities are decimal and must be real. */
    private function assertQuantity(string $quantity): string
    {
        $qty = \App\Support\HospitalSettings::decimal($quantity, 2);

        if (bccomp($qty, '0', 2) <= 0) {
            throw new RuntimeException('Quantity must be greater than zero.');
        }

        return $qty;
    }
}
