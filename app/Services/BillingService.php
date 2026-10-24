<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Enums\InvoiceStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Exceptions\OverpaymentException;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Patient;
use App\Models\PatientCard;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Visit;
use App\Support\HospitalSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Money for visits. Every amount is a scale-2 bcmath string end to end
 * (never a float) and the tax rate comes from the hospital's own config
 * (HospitalSettings) — nothing is hardcoded. Line totals and roll-ups are
 * computed here, never in a model hook. Invoice generation and payments extend
 * this service in Step 11b.
 */
class BillingService
{
    public function __construct(
        private readonly HospitalSettings $settings,
        private readonly CardService $cards,
    ) {}

    /**
     * Put a line on an order.
     *
     * This is the only way money gets onto a bill. Everything else here is a
     * convenience over it, and every line therefore belongs to a piece of work
     * that can be cancelled, attributed and reported on (docs/orders.md).
     *
     * Quantities are decimal, not whole: drugs are dispensed in halves, and a
     * line that cannot say so has to hide the real figure in its own name.
     *
     * A product may be put on WITHOUT being billed (`billed` false,
     * docs/orders.md): it is recorded with no amount, so every total that
     * adds up line totals leaves it out, and the invoice skips it. Its stock
     * movement and the cost that posts to the books are the caller's, exactly
     * as for a billed one. Who made the choice is kept on the line.
     *
     * @param  array{service_id?:int|null,stock_item_id?:int|null,name?:string,unit_price?:string|int|float,quantity?:string|int|float,tax_exempt?:bool,notes?:string|null,billed?:bool}  $attrs
     */
    public function addItem(Order $order, array $attrs, ?int $by = null): OrderItem
    {
        $quantity = \App\Support\HospitalSettings::decimal((string) ($attrs['quantity'] ?? 1), 2);
        $unitPrice = (string) ($attrs['unit_price'] ?? '0');
        $stockItemId = $attrs['stock_item_id'] ?? null;
        $billed = ($attrs['billed'] ?? true) !== false;

        if (bccomp($quantity, '0', 2) <= 0) {
            throw new RuntimeException('Quantity must be greater than zero.');
        }
        if (bccomp($unitPrice, '0', 2) < 0) {
            throw new RuntimeException('Unit price cannot be negative.');
        }
        if (! $billed && $stockItemId === null) {
            throw new RuntimeException('Only a product can be left off the bill. A service is always billed.');
        }

        $unitPrice = $billed ? $unitPrice : '0.00';
        // What one cost when it was used, so "used but not billed" can be
        // valued at cost without reading the stock ledger.
        $unitCost = $stockItemId === null ? null
            : (string) (\App\Models\StockItem::withTrashed()->whereKey($stockItemId)->value('cost_price') ?? '0.00');

        return $order->items()->create([
            'service_id' => $attrs['service_id'] ?? null,
            'stock_item_id' => $stockItemId,
            'name' => $attrs['name'] ?? $order->title,
            'unit_price' => $unitPrice,
            'unit_cost' => $unitCost,
            'quantity' => $quantity,
            'line_total' => bcmul($unitPrice, $quantity, 2),
            'tax_exempt' => (bool) ($attrs['tax_exempt'] ?? false),
            'status' => OrderItemStatus::Ordered,
            'ordered_by' => $by,
            'notes' => $attrs['notes'] ?? null,
            'billed' => $billed,
            'billing_set_by' => $stockItemId === null ? null : $by,
            'billing_set_at' => $stockItemId === null ? null : Carbon::now(),
        ]);
    }

    /**
     * Bill a product line, or take it off the bill, before the visit is invoiced.
     *
     * Not billed means no amount: the price and total go to nothing and every
     * total that adds up lines leaves it out. Billed again means the shelf's
     * selling price today, as when a product is first added. The quantity and
     * the stock that left the shelf do not move. Who chose is kept.
     */
    public function setLineBilled(OrderItem $line, bool $billed, ?int $by = null): OrderItem
    {
        $price = '0.00';
        if ($billed) {
            $price = (string) (\App\Models\StockItem::withTrashed()->whereKey($line->stock_item_id)->value('sale_price') ?? '0.00');
        }

        $line->update([
            'billed' => $billed,
            'unit_price' => $price,
            'line_total' => bcmul($price, (string) $line->quantity, 2),
            'billing_set_by' => $by,
            'billing_set_at' => Carbon::now(),
            'updated_by' => $by,
        ]);

        return $line->fresh() ?? $line;
    }

    /** Add a priced catalogue service to an order, snapshotting name + price. */
    public function addServiceLine(Order $order, Service $service, string|int $quantity = 1, ?int $by = null, ?string $unitPrice = null): OrderItem
    {
        return $this->addItem($order, [
            'service_id' => $service->id,
            'name' => $service->name,
            // The catalogue's price, or — for a price range — the amount chosen within it.
            'unit_price' => $service->chargePrice($unitPrice),
            'quantity' => $quantity,
            'tax_exempt' => $service->tax_exempt,
        ], $by);
    }

    /**
     * Order a catalogue service for a visit: raises the order and its line
     * together, since asking for a priced service IS the piece of work.
     * Resolution runs through the tenant global scope, so another hospital's
     * service id simply does not exist here.
     */
    public function orderService(Visit $visit, int $serviceId, string|int $quantity = 1, ?int $by = null, ?string $unitPrice = null): OrderItem
    {
        /** @var Service $service */
        $service = Service::whereKey($serviceId)->firstOrFail();
        // Refused before anything is written: a price outside the range.
        $service->chargePrice($unitPrice);

        // One transaction: an order that exists without the charge it was
        // raised for would be a completed piece of work with nothing on it.
        return DB::transaction(function () use ($visit, $service, $quantity, $by, $unitPrice) {
            $order = app(OrderService::class)->place(
                $visit,
                OrderType::Consultation,
                $service->name,
                ['status' => OrderStatus::Completed],
                $by,
            );

            return $this->addServiceLine($order, $service, $quantity, $by, $unitPrice);
        });
    }

    /**
     * The bill's Add charge: a service off the price list or a product off
     * the shelf, on an order of its own raised completed, so the charge has a
     * piece of work behind it like every other line (docs/orders.md, "Add
     * charge is not an exception"). A product leaves the shelf in the same
     * transaction as the charge and may be given without billing (`$billed`,
     * null for the hospital's choice); a service priced as a range takes
     * `$unitPrice` inside it.
     *
     * Refused once the visit is invoiced: the invoice is a copy of the bill.
     * The visit row is locked first, the lock generateInvoice() takes, so a
     * charge and an invoice raised at the same moment cannot both pass.
     *
     * The web's bill and `POST /api/v1/visits/{uuid}/bill/lines` both come here.
     */
    public function addCharge(Visit $visit, string $kind, int $id, string $quantity, ?int $by = null, ?string $unitPrice = null, ?bool $billed = null, ?string $note = null): OrderItem
    {
        return DB::transaction(function () use ($visit, $kind, $id, $quantity, $by, $unitPrice, $billed, $note) {
            /** @var Visit $locked */
            $locked = Visit::whereKey($visit->id)->lockForUpdate()->firstOrFail();
            if ($this->hasLiveInvoice($visit)) {
                throw new RuntimeException('This visit has been invoiced, so its charges can no longer be changed.');
            }
            // A cancelled visit is one the hospital decided did not happen;
            // a completed one may still be billed (people pay after they leave).
            if ($locked->status === \App\Enums\VisitStatus::Completed && $locked->outcome === \App\Enums\VisitOutcome::Cancelled) {
                throw new RuntimeException('This visit was cancelled, so nothing can be charged to it.');
            }

            if ($kind === 'product') {
                /** @var \App\Models\StockItem $item */
                $item = \App\Models\StockItem::whereKey($id)->firstOrFail();
                $orders = app(OrderService::class);

                // A walk-in buying paracetamol still goes through an order: the
                // charge and the stock movement happen together.
                $order = $orders->place($visit, OrderType::Pharmacy, $item->name, ['status' => OrderStatus::Completed], $by);
                $line = $orders->addProductItem($order, $item, $quantity, $by, $billed);
            } else {
                $line = $this->orderService($visit, $id, $quantity, $by, $unitPrice);
            }

            $note = trim((string) $note);
            if ($note !== '') {
                $line->update(['notes' => mb_substr($note, 0, 255)]);
            }

            return $line;
        });
    }

    /**
     * Correct a line that is already on the bill.
     *
     * The quantity and the note: name and unit price are SNAPSHOTS taken
     * when the line was raised, and a catalogue that changes its prices must
     * not change what a patient was already charged. The one exception is a
     * unit price the caller has already checked against a service's range
     * (OrderService::allowedPrice). Adjusting the stock that a quantity change
     * implies is OrderService's job; this is the money.
     */
    public function repriceItem(OrderItem $line, string $quantity, ?string $notes, ?int $by = null, ?string $unitPrice = null): OrderItem
    {
        $qty = \App\Support\HospitalSettings::decimal($quantity, 2);

        if (bccomp($qty, '0', 2) <= 0) {
            throw new RuntimeException('Quantity must be greater than zero.');
        }

        $unit = $unitPrice !== null ? \App\Support\HospitalSettings::decimal($unitPrice, 2) : (string) $line->unit_price;

        $line->update([
            'quantity' => $qty,
            'unit_price' => $unit,
            'line_total' => bcmul($unit, $qty, 2),
            'notes' => ($notes ?? '') === '' ? null : $notes,
            'updated_by' => $by,
        ]);

        return $line->fresh();
    }

    public function cancelLine(OrderItem $line): OrderItem
    {
        $line->update(['status' => OrderItemStatus::Cancelled]);

        return $line->fresh();
    }

    /**
     * Take every line of an order off the bill. Called when the work itself is
     * cancelled — the money follows the work.
     */
    public function cancelItemsOf(Order $order): void
    {
        $order->items()
            ->where('status', '!=', OrderItemStatus::Cancelled->value)
            ->update(['status' => OrderItemStatus::Cancelled->value]);
    }

    /**
     * Cancel a line *of this visit*. A line id belonging to another visit (or
     * another tenant) is a 404, never a silent cross-visit write.
     */
    /**
     * Put every live line back in step with its own parts.
     *
     * `line_total` is stored rather than derived, so that a price change in
     * the catalogue can never move a charge already raised. The cost of that
     * is that a line CAN drift — a bad import, a hand-edited row, a bug in
     * something that wrote it — and nothing would ever notice.
     *
     * This is the thing that notices. It recomputes each live line from its
     * own unit price and quantity, reports what it had to change, and refuses
     * on an invoiced visit because those lines have been snapshotted onto a
     * document that has already been issued.
     *
     * @return array{checked:int,fixed:int,lines:list<string>}
     */
    public function recompute(Visit $visit, ?int $by = null): array
    {
        return DB::transaction(function () use ($visit, $by) {
            // The lock invoicing takes: lines are never rewritten under an invoice being raised.
            Visit::whereKey($visit->id)->lockForUpdate()->first();
            if ($this->hasLiveInvoice($visit)) {
                throw new RuntimeException('This visit has been invoiced — its lines are fixed.');
            }

            $checked = 0;
            $fixed = [];

            foreach ($visit->orderItems()->get() as $line) {
                if (! $line->status->isBillable()) {
                    continue;
                }

                $checked++;
                $should = bcmul((string) $line->unit_price, (string) $line->quantity, 2);

                if (bccomp($should, (string) $line->line_total, 2) !== 0) {
                    $fixed[] = $line->name.': '.$line->line_total.' → '.$should;
                    $line->update(['line_total' => $should, 'updated_by' => $by]);
                }
            }

            return ['checked' => $checked, 'fixed' => count($fixed), 'lines' => $fixed];
        });
    }

    /**
     * Agree a discount on this visit, before any invoice is raised.
     *
     * Capped at what is actually owed — a discount larger than the bill would
     * be the hospital paying the patient — and refused once an invoice exists,
     * because the discount is printed on it.
     */
    public function applyDiscount(Visit $visit, DiscountType $type, string $value, ?string $reason, ?int $by = null): Visit
    {
        return DB::transaction(function () use ($visit, $type, $value, $reason, $by) {
            // The lock invoicing takes: a discount never changes under an invoice being raised.
            Visit::whereKey($visit->id)->lockForUpdate()->first();
            $visit->unsetRelation('orderItems');

            return $this->applyDiscountLocked($visit, $type, $value, $reason, $by);
        });
    }

    private function applyDiscountLocked(Visit $visit, DiscountType $type, string $value, ?string $reason, ?int $by): Visit
    {
        if ($this->hasLiveInvoice($visit)) {
            throw new RuntimeException('This visit has been invoiced — its discount is on the invoice now.');
        }

        $entered = HospitalSettings::decimal($value, 2);

        if (bccomp($entered, '0', 2) < 0) {
            throw new RuntimeException('A discount cannot be negative.');
        }

        if ($type === DiscountType::Percent && bccomp($entered, '100', 2) > 0) {
            throw new RuntimeException('A discount cannot be more than 100%.');
        }

        // A fixed amount is checked against the bill as it stands. A
        // percentage is not: it is a rule, and it stays right as the bill moves.
        if ($type === DiscountType::Amount) {
            $subtotal = $this->figures($visit)['subtotal'];
            if (bccomp($entered, $subtotal, 2) > 0) {
                throw new RuntimeException(
                    'A discount cannot be more than the bill — '.HospitalSettings::money($subtotal).'.'
                );
            }
        }

        $given = bccomp($entered, '0', 2) > 0;

        $visit->forceFill([
            'discount_value' => $entered,
            'discount_type' => $type,
            'discount_reason' => $given ? (trim((string) $reason) ?: null) : null,
            'discounted_by' => $given ? $by : null,
        ])->save();

        return $visit->refresh();
    }

    public function hasLiveInvoice(Visit $visit): bool
    {
        return Invoice::where('visit_id', $visit->id)
            ->where('status', '!=', InvoiceStatus::Void->value)
            ->exists();
    }

    /**
     * Roll up the billable lines of a visit using the hospital's tax config and
     * whatever discount the visit is carrying. Tax applies only to non-exempt
     * lines. All scale-2 strings.
     *
     * `total` is what the work came to; `due` is what there is to pay. They
     * differ by the discount, and quoting the wrong one is how a screen ends up
     * asking for money nobody owes.
     *
     * @return array{subtotal:string,taxable:string,tax:string,discount:string,total:string,due:string,currency:string}
     */
    public function totalsFor(Visit $visit): array
    {
        return $this->figures($visit, $this->discountFor($visit));
    }

    /**
     * What this visit comes to, with a given discount.
     *
     * One place does the arithmetic, so the figure on the screen and the
     * figure written on the invoice cannot differ.
     *
     * **Tax is charged on what is actually paid.** A discount reduces the
     * taxable base in proportion to how much of the bill is taxable, and the
     * tax is worked out on what is left. It used to be computed on the full
     * base with the discount taken off afterwards, which charged the patient
     * VAT on money they were never asked for.
     *
     * @return array{subtotal:string,taxable:string,tax:string,discount:string,total:string,due:string,currency:string}
     */
    public function figures(Visit $visit, string $discount = '0.00'): array
    {
        $subtotal = '0.00';
        $taxableGross = '0.00';

        foreach ($visit->orderItems as $line) {
            if (! $line->status->isBillable()) {
                continue;
            }
            $subtotal = bcadd($subtotal, (string) $line->line_total, 2);
            if (! $line->tax_exempt) {
                $taxableGross = bcadd($taxableGross, (string) $line->line_total, 2);
            }
        }

        return $this->compute($subtotal, $taxableGross, $discount);
    }

    /**
     * The arithmetic itself, with the lines already added up.
     *
     * Split out so that a screen which sums the lines in SQL — the visits
     * listing adds up a page of bills in one statement rather than loading
     * every line of every visit — gets the SAME tax and discount treatment as
     * the bill panel. Two implementations of this would be two answers to
     * "what does this visit come to".
     *
     * @return array{subtotal:string,taxable:string,tax:string,discount:string,total:string,due:string,currency:string}
     */
    public function compute(string $subtotal, string $taxableGross, string $discount = '0.00'): array
    {
        $subtotal = HospitalSettings::decimal($subtotal, 2);
        $taxableGross = HospitalSettings::decimal($taxableGross, 2);

        // Never more than the bill, however it was arrived at.
        $discount = HospitalSettings::decimal($discount, 2);
        if (bccomp($discount, $subtotal, 2) > 0) {
            $discount = $subtotal;
        }

        // The discount comes off the taxable part in the same proportion it
        // comes off the bill — a half-exempt bill discounted by a tenth loses
        // a tenth of its taxable value, not all of it and not none.
        $taxable = bccomp($subtotal, '0', 2) > 0
            ? bcdiv(bcmul($taxableGross, bcsub($subtotal, $discount, 2), 4), $subtotal, 2)
            : '0.00';

        $rate = $this->settings->taxRate();                       // e.g. "18.0000"
        $tax = bccomp($rate, '0', 4) > 0
            ? bcmul($taxable, bcdiv($rate, '100', 6), 2)
            : '0.00';

        return [
            'subtotal' => $subtotal,
            'taxable' => $taxable,
            'tax' => $tax,
            'discount' => $discount,
            // What the work came to, with the tax actually charged on it.
            'total' => bcadd($subtotal, $tax, 2),
            // And what there is to pay.
            'due' => bcadd(bcsub($subtotal, $discount, 2), $tax, 2),
            'currency' => $this->settings->currencyCode(),
        ];
    }

    /** The money this visit's standing discount is worth right now. */
    public function discountFor(Visit $visit): string
    {
        $subtotal = '0.00';
        foreach ($visit->orderItems as $line) {
            if ($line->status->isBillable()) {
                $subtotal = bcadd($subtotal, (string) $line->line_total, 2);
            }
        }

        return ($visit->discount_type ?? DiscountType::Amount)
            ->resolve((string) ($visit->discount_value ?? '0'), $subtotal);
    }

    /**
     * Generate an invoice from a visit's billable lines. One live invoice
     * per visit (a re-generate is blocked while a non-void invoice exists).
     * Runs in a transaction: number allocation, roll-ups and item snapshots are
     * all-or-nothing. Discount is a flat amount, 0..(subtotal+tax); null takes
     * the discount agreed on the visit, worked out under the lock.
     *
     * The visit row is locked FIRST and the lines are read after it, so a
     * charge, a correction or a discount committed a moment earlier is on the
     * invoice, and one that comes a moment later waits and is then refused.
     */
    public function generateInvoice(Visit $visit, ?string $discount = '0.00', ?int $by = null): Invoice
    {
        if ($discount !== null && bccomp($discount, '0', 2) < 0) {
            throw new RuntimeException('Discount cannot be negative.');
        }

        // Can't post an invoice into a closed accounting period.
        app(\App\Services\FinancialYearService::class)->assertPostingAllowed(Carbon::now());

        return DB::transaction(function () use ($visit, $discount, $by) {
            // Serialise per visit: two concurrent "generate" clicks must
            // not both pass the existence check (plan finding K3).
            /** @var Visit $visit */
            $visit = Visit::whereKey($visit->id)->lockForUpdate()->firstOrFail();
            $this->assertNoOpenInvoice($visit);

            $visit->load('orderItems');
            $discount ??= $this->discountFor($visit);

            // The same arithmetic the screen showed, with the same discount — so
            // what was read at the counter is what gets printed.
            $t = $this->figures($visit, $discount);

            if (bccomp($discount, $t['subtotal'], 2) > 0) {
                throw new RuntimeException('Discount cannot exceed the invoice amount.');
            }
            $total = $t['due'];

            $invoice = $this->createInvoiceWithNumber([
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'currency' => $this->settings->currencyCode(),
                'subtotal' => $t['subtotal'],
                'tax_total' => $t['tax'],
                'discount' => $discount,
                'total' => $total,
                'amount_paid' => '0.00',
                'balance' => $total,
                'status' => InvoiceStatus::Issued,
                'issued_by' => $by,
                'issued_at' => Carbon::now(),
            ]);

            foreach ($visit->orderItems as $line) {
                // A product used without billing stays on its order, marked
                // Not billed; the patient's invoice does not list it.
                if (! $line->status->isBillable() || ! $line->isBilled()) {
                    continue;
                }
                $invoice->items()->create([
                    'description' => $line->name,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'line_total' => $line->line_total,
                    'tax_exempt' => $line->tax_exempt,
                    'source_type' => $line->getMorphClass(),
                    'source_id' => $line->id,
                ]);
            }

            // Into the books in the same transaction: an invoice that exists
            // without its income would be revenue nobody can see.
            app(\App\Services\Finance\Bookkeeper::class)->invoiceIssued($invoice);

            return $invoice;
        });
    }

    /**
     * What a patient owed before the system, brought forward as an invoice of
     * its own (Settings, Import data; docs/imports.md).
     *
     * An invoice, not a number written onto the patient, so the debt is in
     * the ONE definition of outstanding: the patient's balance, the debtors
     * list and the cashier all see it, and it is paid like any other bill. It
     * has one line, no tax and no discount, sits on a visit of its own that is
     * not an attendance, and goes into the books against opening equity
     * rather than as this year's income (Bookkeeper::invoiceIssued).
     */
    public function openingBalance(Patient $patient, string $amount, ?int $by = null, ?string $note = null): Invoice
    {
        $amount = HospitalSettings::decimal($amount, 2);
        if (bccomp($amount, '0', 2) <= 0) {
            throw new RuntimeException('A balance brought forward must be more than zero.');
        }

        app(\App\Services\FinancialYearService::class)->assertPostingAllowed(Carbon::now());

        return DB::transaction(function () use ($patient, $amount, $by, $note) {
            // Every bill belongs to a visit (the database insists). This one
            // gets a visit of its own that is called off at once: "cancelled"
            // is how every report that counts attendances knows a visit was
            // not one, so a clinic bringing in 400 debtors does not report 400
            // attendances to the Ministry on the day it imported them.
            $visits = app(VisitService::class);
            $visit = $visits->open(['patient_id' => $patient->id, 'reason' => 'Balance brought forward from the old records'], $by);
            $visits->cancel($visit, $by, 'Not an attendance: it carries the balance brought forward from the old records.');

            $invoice = $this->createInvoiceWithNumber([
                'visit_id' => $visit->id,
                'patient_id' => $patient->id,
                'currency' => $this->settings->currencyCode(),
                'subtotal' => $amount,
                'tax_total' => '0.00',
                'discount' => '0.00',
                'total' => $amount,
                'amount_paid' => '0.00',
                'balance' => $amount,
                'status' => InvoiceStatus::Issued,
                'opening_balance' => true,
                'notes' => $note ?? 'Balance brought forward from the old records',
                'issued_by' => $by,
                'issued_at' => Carbon::now(),
            ]);

            $invoice->items()->create([
                'description' => 'Balance brought forward',
                'quantity' => '1',
                'unit_price' => $amount,
                'line_total' => $amount,
                'tax_exempt' => true,
            ]);

            app(\App\Services\Finance\Bookkeeper::class)->invoiceIssued($invoice);

            return $invoice;
        });
    }

    /**
     * The bill of a visit brought in from the old records (Settings, Import
     * data, Visits; docs/imports.md), as an opening entry like a balance
     * brought forward.
     *
     * The invoice carries the visit's lines and the amount billed, dated on
     * the visit's day. What was paid then was paid in the old books: it is
     * written on the invoice as `paid_before`, never as a payment here, so it
     * is never today's takings. Only what was still owed goes into these
     * books, against opening equity (Bookkeeper::invoiceIssued), and it is
     * owed and paid like any other bill.
     */
    public function billPastVisit(Visit $visit, string $billed, string $paid, ?int $by = null): Invoice
    {
        $billed = HospitalSettings::decimal($billed, 2);
        $paid = HospitalSettings::decimal($paid, 2);
        if (bccomp($billed, '0', 2) <= 0) {
            throw new RuntimeException('A visit from the old records is billed only when an amount was billed.');
        }
        if (bccomp($paid, '0', 2) < 0 || bccomp($paid, $billed, 2) > 0) {
            throw new RuntimeException('The amount paid must be between nothing and the amount billed.');
        }

        app(\App\Services\FinancialYearService::class)->assertPostingAllowed(Carbon::now());

        return DB::transaction(function () use ($visit, $billed, $paid, $by) {
            Visit::whereKey($visit->id)->lockForUpdate()->first();
            $this->assertNoOpenInvoice($visit);

            $balance = bcsub($billed, $paid, 2);
            $on = $visit->completed_at ?? $visit->created_at ?? Carbon::now();

            $invoice = $this->createInvoiceWithNumber([
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'currency' => $this->settings->currencyCode(),
                'subtotal' => $billed,
                'tax_total' => '0.00',
                'discount' => '0.00',
                'total' => $billed,
                'amount_paid' => $paid,
                'paid_before' => $paid,
                'balance' => $balance,
                'status' => match (true) {
                    bccomp($balance, '0', 2) === 0 => InvoiceStatus::Paid,
                    bccomp($paid, '0', 2) > 0 => InvoiceStatus::PartiallyPaid,
                    default => InvoiceStatus::Issued,
                },
                'opening_balance' => true,
                'notes' => 'Visit from the old records',
                'issued_by' => $by,
                'issued_at' => $on,
            ]);

            foreach ($visit->orderItems()->get() as $line) {
                if (! $line->status->isBillable() || ! $line->isBilled()) {
                    continue;
                }
                $invoice->items()->create([
                    'description' => $line->name,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'line_total' => $line->line_total,
                    'tax_exempt' => true,
                    'source_type' => $line->getMorphClass(),
                    'source_id' => $line->id,
                ]);
            }

            app(\App\Services\Finance\Bookkeeper::class)->invoiceIssued($invoice);

            return $invoice;
        });
    }

    /**
     * Take back a balance brought forward that nothing has touched (an import
     * undone): the invoice is voided and its entry comes back out of the
     * books. Refused once anything was paid or credited against it.
     */
    public function voidOpeningBalance(Invoice $invoice): void
    {
        if (! $invoice->opening_balance) {
            throw new RuntimeException('Only a balance brought forward can be taken back this way.');
        }

        $this->voidInvoice($invoice);
    }

    /**
     * Why this invoice cannot be voided, or null when it can: money taken
     * here and not refunded, a credit note, or an insurance claim on it.
     */
    public function voidBlocker(Invoice $invoice): ?string
    {
        if ($invoice->status === InvoiceStatus::Void) {
            return null;
        }

        return match (true) {
            bccomp($invoice->heldHere(), '0', 2) > 0 => HospitalSettings::money($invoice->heldHere()).' has been paid on invoice '.$invoice->invoice_no.'. Refund it first.',
            bccomp((string) ($invoice->credited ?? '0'), '0', 2) !== 0 => 'Invoice '.$invoice->invoice_no.' has a credit note on it.',
            $invoice->credits()->where('status', \App\Enums\CreditStatus::Pending->value)->exists() => 'Invoice '.$invoice->invoice_no.' has a credit note or refund waiting for approval.',
            \App\Models\InsuranceClaim::query()->where('invoice_id', $invoice->id)->exists() => 'Invoice '.$invoice->invoice_no.' has an insurance claim on it.',
            default => null,
        };
    }

    /**
     * Void an invoice nobody has paid (a visit deleted, an import undone):
     * it stops being owed, and its entry comes back out of the books as a
     * reversing entry dated today. Never into a closed period.
     */
    public function voidInvoice(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            /** @var Invoice $locked */
            $locked = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === InvoiceStatus::Void) {
                return;
            }
            if (($why = $this->voidBlocker($locked)) !== null) {
                throw new RuntimeException($why);
            }

            app(\App\Services\FinancialYearService::class)->assertPostingAllowed(Carbon::now());

            $locked->update(['status' => InvoiceStatus::Void, 'balance' => '0.00']);
            app(\App\Services\Finance\Bookkeeper::class)->invoiceVoided($locked);
        });
    }

    /**
     * Record a payment against an invoice. Runs in a transaction with a locking
     * read on the invoice (no lost updates on concurrent payments). Rejects
     * overpayment and non-payable invoices. A card payment debits the patient's
     * prepaid card via CardService (which itself locks the card + writes the
     * ledger); if the card lacks funds that exception rolls the whole thing back.
     */
    public function recordPayment(Invoice $invoice, PaymentMethod $method, string $amount, array $opts = [], ?int $by = null): Payment
    {
        // Money cannot be posted into a closed accounting period (plan finding K8).
        app(\App\Services\FinancialYearService::class)->assertPostingAllowed(Carbon::now());

        if (bccomp($amount, '0', 2) <= 0) {
            throw new RuntimeException('Payment amount must be greater than zero.');
        }

        return DB::transaction(function () use ($invoice, $method, $amount, $opts, $by) {
            /** @var Invoice $locked */
            $locked = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isPayable()) {
                throw new RuntimeException("Invoice is {$locked->status->label()}; no payment allowed.");
            }
            if (bccomp($amount, (string) $locked->balance, 2) > 0) {
                throw OverpaymentException::make($amount, (string) $locked->balance);
            }

            $cardId = null;
            $cardRecordId = null;
            if ($method === PaymentMethod::Card) {
                /** @var PatientCard|null $card */
                $card = $opts['card'] ?? null;

                // `covers()`, not a comparison of patient ids: a card may be
                // held by a whole family, and this used to be the line that
                // made a mother's card unable to pay for her child
                // (docs/cards.md).
                if (! $card instanceof PatientCard || ! $card->covers($locked->patient_id)) {
                    throw new RuntimeException('A valid card for this patient is required.');
                }

                // The debit is stamped with WHO it was spent on, which on a
                // family card is the patient of this invoice rather than the
                // person the card belongs to. The insurer's usage report is
                // made of exactly that.
                $record = $this->cards->debit(
                    $card,
                    $amount,
                    "Invoice {$locked->invoice_no}",
                    $by,
                    // The payment below is what the books record; the card
                    // debit is the same money, and posting both would count it twice.
                    ['for' => $locked->patient_id, 'reference' => $locked->invoice_no, 'books' => false],
                );
                $cardId = $card->id;
                $cardRecordId = $record->id;
            }

            $newPaid = bcadd((string) $locked->amount_paid, $amount, 2);
            // What the invoice asks for after its credit notes, less what is
            // held against it after its refunds (docs/refunds.md).
            $newBalance = bcsub($locked->netTotal(), bcsub($newPaid, (string) $locked->refunded, 2), 2);
            $status = bccomp($newBalance, '0', 2) === 0 ? InvoiceStatus::Paid : InvoiceStatus::PartiallyPaid;

            $locked->update(['amount_paid' => $newPaid, 'balance' => $newBalance, 'status' => $status]);
            if ($status === InvoiceStatus::Paid) {
                \App\Support\Funnel::reached((int) $locked->hospital_id, 'first_paid_visit');
            }

            $payment = Payment::create([
                'uuid' => (string) Str::uuid(),
                'invoice_id' => $locked->id,
                'patient_id' => $locked->patient_id,
                'method' => $method,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'patient_card_id' => $cardId,
                'card_record_id' => $cardRecordId,
                'reference' => $opts['reference'] ?? null,
                'received_by' => $by,
                'created_at' => Carbon::now(),
            ]);

            app(\App\Services\Finance\Bookkeeper::class)->paymentReceived($payment);

            // Paid in full: the visit closes itself. Completing a settled
            // visit is nobody's decision, and leaving it sitting at Payment is
            // how a finished attendance stays on somebody's list for a week
            // (docs/visits.md).
            $visit = $locked->visit;
            if ($visit !== null) {
                app(\App\Services\VisitService::class)->reconcile($visit, $by);
            }

            if ($status === InvoiceStatus::Paid) {
                $this->sendReceipt($locked, $payment);
            }

            return $payment;
        });
    }

    /**
     * "Thank you. Your receipt: …" to the patient once the bill is paid in
     * full (roadmap 05 §3), with a link to the receipt that expires. Only
     * where the hospital has messages on: the link is not made otherwise.
     */
    private function sendReceipt(Invoice $invoice, Payment $payment): void
    {
        $patient = Patient::query()->withoutGlobalScopes()->find($invoice->patient_id);
        $hospital = \App\Models\Hospital::query()->find($invoice->hospital_id);
        if ($patient === null || ! \App\Services\Messaging\MessagingSettings::of($hospital)->smsEnabled()) {
            return;
        }

        try {
            $link = app(InvoiceShareService::class)->sharePayment($payment)['url'];
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        app(\App\Services\Messaging\PatientMessenger::class)->trySend($patient, 'receipt', [
            'amount' => HospitalSettings::money($invoice->total),
            'invoice' => $invoice->invoice_no,
            'link' => $link,
        ], 'invoice:'.$invoice->uuid.':paid');
    }

    private function assertNoOpenInvoice(Visit $visit): void
    {
        if ($this->hasLiveInvoice($visit)) {
            throw new RuntimeException('This visit already has an invoice.');
        }
    }

    private function createInvoiceWithNumber(array $attributes, int $attempt = 0): Invoice
    {
        $now = Carbon::now();

        try {
            $year = (int) $now->format('Y');
            $seq = \App\Support\Sequence::next('invoice', (string) $year, fn () => Invoice::withTrashed()->whereYear('created_at', $year)->count());

            return Invoice::create(array_merge($attributes, [
                'uuid' => (string) Str::uuid(),
                'invoice_no' => $this->settings->invoicePrefix().'-'.$now->format('Y').'-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            ]));
        } catch (UniqueConstraintViolationException $e) {
            if ($attempt >= 3) {
                throw $e;
            }

            return $this->createInvoiceWithNumber($attributes, $attempt + 1);
        }
    }
}
