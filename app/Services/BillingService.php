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
     * @param  array{service_id?:int|null,stock_item_id?:int|null,name?:string,unit_price?:string|int|float,quantity?:string|int|float,tax_exempt?:bool,notes?:string|null}  $attrs
     */
    public function addItem(Order $order, array $attrs, ?int $by = null): OrderItem
    {
        $quantity = \App\Support\HospitalSettings::decimal((string) ($attrs['quantity'] ?? 1), 2);
        $unitPrice = (string) ($attrs['unit_price'] ?? '0');

        if (bccomp($quantity, '0', 2) <= 0) {
            throw new RuntimeException('Quantity must be greater than zero.');
        }
        if (bccomp($unitPrice, '0', 2) < 0) {
            throw new RuntimeException('Unit price cannot be negative.');
        }

        return $order->items()->create([
            'service_id' => $attrs['service_id'] ?? null,
            'stock_item_id' => $attrs['stock_item_id'] ?? null,
            'name' => $attrs['name'] ?? $order->title,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'line_total' => bcmul($unitPrice, $quantity, 2),
            'tax_exempt' => (bool) ($attrs['tax_exempt'] ?? false),
            'status' => OrderItemStatus::Ordered,
            'ordered_by' => $by,
            'notes' => $attrs['notes'] ?? null,
        ]);
    }

    /** Add a priced catalogue service to an order, snapshotting name + price. */
    public function addServiceLine(Order $order, Service $service, string|int $quantity = 1, ?int $by = null): OrderItem
    {
        return $this->addItem($order, [
            'service_id' => $service->id,
            'name' => $service->name,
            'unit_price' => (string) $service->price,
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
    public function orderService(Visit $visit, int $serviceId, string|int $quantity = 1, ?int $by = null): OrderItem
    {
        /** @var Service $service */
        $service = Service::whereKey($serviceId)->firstOrFail();

        // One transaction: an order that exists without the charge it was
        // raised for would be a completed piece of work with nothing on it.
        return DB::transaction(function () use ($visit, $service, $quantity, $by) {
            $order = app(OrderService::class)->place(
                $visit,
                OrderType::Consultation,
                $service->name,
                ['status' => OrderStatus::Completed],
                $by,
            );

            return $this->addServiceLine($order, $service, $quantity, $by);
        });
    }

    /**
     * Correct a line that is already on the bill.
     *
     * Only the quantity and the note: name and unit price are SNAPSHOTS taken
     * when the line was raised, and a catalogue that changes its prices must
     * not change what a patient was already charged. Adjusting the stock that
     * a quantity change implies is OrderService's job — this is the money.
     */
    public function repriceItem(OrderItem $line, string $quantity, ?string $notes, ?int $by = null): OrderItem
    {
        $qty = \App\Support\HospitalSettings::decimal($quantity, 2);

        if (bccomp($qty, '0', 2) <= 0) {
            throw new RuntimeException('Quantity must be greater than zero.');
        }

        $line->update([
            'quantity' => $qty,
            'line_total' => bcmul((string) $line->unit_price, $qty, 2),
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
     * all-or-nothing. Discount is a flat amount, 0..(subtotal+tax).
     */
    public function generateInvoice(Visit $visit, string $discount = '0.00', ?int $by = null): Invoice
    {
        if (bccomp($discount, '0', 2) < 0) {
            throw new RuntimeException('Discount cannot be negative.');
        }

        // Can't post an invoice into a closed accounting period.
        app(\App\Services\FinancialYearService::class)->assertPostingAllowed(Carbon::now());

        $this->assertNoOpenInvoice($visit);

        $visit->loadMissing('orderItems');

        // The same arithmetic the screen showed, with the same discount — so
        // what was read at the counter is what gets printed.
        $t = $this->figures($visit, $discount);

        if (bccomp($discount, $t['subtotal'], 2) > 0) {
            throw new RuntimeException('Discount cannot exceed the invoice amount.');
        }
        $total = $t['due'];

        return DB::transaction(function () use ($visit, $t, $discount, $total, $by) {
            // Serialise per visit: two concurrent "generate" clicks must
            // not both pass the existence check (plan finding K3).
            Visit::whereKey($visit->id)->lockForUpdate()->first();
            $this->assertNoOpenInvoice($visit);

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
                if (! $line->status->isBillable()) {
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

            return $invoice;
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
                    ['for' => $locked->patient_id, 'reference' => $locked->invoice_no],
                );
                $cardId = $card->id;
                $cardRecordId = $record->id;
            }

            $newPaid = bcadd((string) $locked->amount_paid, $amount, 2);
            $newBalance = bcsub((string) $locked->total, $newPaid, 2);
            $status = bccomp($newBalance, '0', 2) === 0 ? InvoiceStatus::Paid : InvoiceStatus::PartiallyPaid;

            $locked->update(['amount_paid' => $newPaid, 'balance' => $newBalance, 'status' => $status]);

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

            // Paid in full: the visit closes itself. Completing a settled
            // visit is nobody's decision, and leaving it sitting at Payment is
            // how a finished attendance stays on somebody's list for a week
            // (docs/visits.md).
            $visit = $locked->visit;
            if ($visit !== null) {
                app(\App\Services\VisitService::class)->reconcile($visit, $by);
            }

            return $payment;
        });
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
