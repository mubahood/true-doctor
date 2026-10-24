<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Enums\DiscountType;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\InvoiceGenerateRequest;
use App\Http\Requests\PaymentRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\PatientCard;
use App\Models\Service;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\OrderDesk;
use App\Support\ApiResponse;
use App\Support\CurrentHospital;
use App\Support\HospitalSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * A visit's bill (web: the visit's Charges and Payments panels): the lines,
 * the totals by BillingService's arithmetic, the invoice and what is still
 * unbilled; adding a charge at the counter, the discount agreed there and
 * putting the bill back in step; raising the invoice; taking a payment with
 * PaymentRequest (docs/api/orders-and-bill.md).
 *
 * Reading is VisitPolicy::viewBill, as the panel: whoever sees the clinic's
 * money sees every bill, a doctor who only puts charges on bills sees their
 * own patients'. Every change needs billing.manage, a discount billing.discount
 * as well. A line is changed on the order that raised it (OrderController),
 * never here: that keeps the bill, the work and the shelf saying the same.
 */
class BillingController extends Controller
{
    public function __construct(private readonly BillingService $billing) {}

    public function bill(Request $request, Visit $visit): JsonResponse
    {
        $this->authorize('view', $visit);
        $this->authorize('viewBill', $visit);

        return ApiResponse::success($this->payload($request, $visit));
    }

    /**
     * Add a charge (web: the bill's Add charge): a service off the price list
     * or a product off the shelf, on an order of its own, so the charge has a
     * piece of work behind it. A product leaves the shelf with it and may be
     * given without billing (`billed`); a service priced as a range takes
     * `unit_price` inside it. Answers the bill as it now stands.
     */
    public function addCharge(Request $request, Visit $visit): JsonResponse
    {
        $this->authorizeWrite($request, $visit);

        $hospital = app(CurrentHospital::class)->id();
        // A range is only known once the service is: looked up in this
        // hospital (the global scope), and only an active one is offered.
        $service = $request->input('kind') === 'service' && is_numeric($request->input('service_id'))
            ? Service::where('is_active', true)->find((int) $request->input('service_id'))
            : null;
        $range = $service !== null && $service->hasRange() ? ['from' => (string) $service->price, 'to' => (string) $service->max_price] : null;

        $data = $request->validate([
            'kind' => ['required', Rule::in(['service', 'product'])],
            'service_id' => ['required_if:kind,service', 'nullable', 'integer',
                Rule::exists('services', 'id')->where('hospital_id', $hospital)->where('is_active', true)->whereNull('deleted_at')],
            'stock_item_id' => ['required_if:kind,product', 'nullable', 'integer',
                Rule::exists('stock_items', 'id')->where('hospital_id', $hospital)->where('is_active', true)->whereNull('deleted_at')],
            // Plain decimals only: "1e3" passes `numeric` but not bcmath.
            'quantity' => ['required', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'unit_price' => $range === null ? ['nullable'] : ['required', 'decimal:0,2', 'between:'.$range['from'].','.$range['to']],
            'note' => ['nullable', 'string', 'max:255'],
            // A product only: billed, or given without billing; left out, the hospital's choice.
            'billed' => ['sometimes', 'nullable', 'boolean'],
        ], [
            'service_id.required_if' => 'Choose a service.',
            'service_id.exists' => 'That service is not on the price list.',
            'stock_item_id.required_if' => 'Choose a product.',
            'stock_item_id.exists' => 'That product is not in the store.',
            'quantity.gt' => 'Quantity must be more than zero.',
            'quantity.decimal' => 'Quantity is a number with at most two decimals.',
            'unit_price.required' => 'Say what to charge within the range.',
            'unit_price.between' => $range === null ? '' : sprintf('Charge between %s and %s.', HospitalSettings::money($range['from']), HospitalSettings::money($range['to'])),
        ]);

        // Said about the bill, not a field: the invoice is a copy of it. The
        // service asks again under the visit's lock.
        if ($this->billing->hasLiveInvoice($visit)) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, 'This visit has been invoiced, so its charges can no longer be changed.', 422);
        }

        $product = $data['kind'] === 'product';

        try {
            $this->billing->addCharge(
                $visit,
                $data['kind'],
                (int) ($product ? $data['stock_item_id'] : $data['service_id']),
                (string) $data['quantity'],
                $request->user()->id,
                $range === null ? null : (string) $data['unit_price'],
                $product && isset($data['billed']) ? (bool) $data['billed'] : null,
                $data['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            // "Not enough Amoxicillin in stock: 3 available, 10 requested", by the pick (as the panel).
            $field = $product ? 'stock_item_id' : 'service_id';

            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422, [$field => [$e->getMessage()]]);
        }

        return ApiResponse::success($this->payload($request, $visit), 'Added to the bill.', 201);
    }

    /**
     * The discount agreed at the counter (web: the bill's Discount dialog): a
     * fixed amount, or a percentage that follows the bill, with the reason;
     * whoever gives it is recorded. Nothing (or 0) clears it. Refused once
     * the visit is invoiced, and an amount larger than the bill.
     */
    public function discount(Request $request, Visit $visit): JsonResponse
    {
        $this->authorizeWrite($request, $visit);
        abort_unless($request->user()->can('billing.discount'), 403);

        $data = $request->validate([
            'type' => ['required', Rule::enum(DiscountType::class)],
            'value' => ['nullable', 'decimal:0,2', 'min:0'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [
            'type.required' => 'Say whether it is an amount or a percentage.',
            'value.decimal' => 'The discount is a number with at most two decimals.',
        ]);

        try {
            $this->billing->applyDiscount($visit, DiscountType::from($data['type']), (string) ($data['value'] ?? '0'), $data['reason'] ?? null, $request->user()->id);
        } catch (RuntimeException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422, ['value' => [$e->getMessage()]]);
        }

        return ApiResponse::success($this->payload($request, $visit), 'Discount saved.');
    }

    /**
     * Put the bill back in step with itself (web: the bill's refresh button,
     * BillingService::recompute): every standing line from its own unit price
     * and quantity. Refused once invoiced. `rebill` says what was checked and
     * what was put right.
     */
    public function rebill(Request $request, Visit $visit): JsonResponse
    {
        $this->authorizeWrite($request, $visit);

        try {
            $result = $this->billing->recompute($visit, $request->user()->id);
        } catch (RuntimeException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422);
        }

        return ApiResponse::success($this->payload($request, $visit) + ['rebill' => $result], $result['fixed'] > 0
            ? $result['fixed'].' line(s) were out of step and have been put right.'
            : 'The bill is in step — '.$result['checked'].' line(s) checked.');
    }

    /** Whoever may read this bill and change it (web: Charges::authorizeWrite). */
    private function authorizeWrite(Request $request, Visit $visit): void
    {
        $this->authorize('view', $visit);
        $this->authorize('viewBill', $visit);
        abort_unless($request->user()->can('billing.manage'), 403);
    }

    /**
     * The bill as the panel draws it, for the phone to draw the same.
     *
     * `lines` are the standing lines (old apps list them as charges, so a
     * removed one is never among them) with the order that raised each and
     * whether this reader may change it there; `removed_lines` those taken
     * off, kept for the trail. Every amount is a two-decimal string.
     *
     * @return array<string,mixed>
     */
    private function payload(Request $request, Visit $visit): array
    {
        $user = $request->user();
        // Fresh: a write just now may have moved the lines or the discount.
        $visit = $visit->fresh() ?? $visit;

        $invoice = $visit->invoices()->where('status', '!=', InvoiceStatus::Void->value)->with(['patient', 'items', 'payments.receivedBy'])->first();
        $totals = $this->billing->totalsFor($visit);

        // Work charged after the invoice was raised (a bed charge at discharge,
        // say) — the invoice snapshots its lines; the bill keeps adding up.
        $unbilled = '0.00';
        if ($invoice !== null) {
            $gap = bcsub($totals['due'], (string) $invoice->total, 2);
            $unbilled = bccomp($gap, '0', 2) > 0 ? $gap : '0.00';
        }

        // A line is changed on its order: by whoever may put work on a visit,
        // while the order stands and nothing is invoiced (the order dialog's rule).
        $writable = OrderDesk::canWrite($user) && $invoice === null;

        $lines = [];
        $removed = [];
        foreach ($visit->orderItems()->with('order')->orderBy('order_items.order_id')->orderBy('order_items.id')->get() as $line) {
            $order = $line->order;
            $standing = $line->status->isBillable();
            $row = [
                'id' => (int) $line->id,
                'name' => $line->name,
                'kind' => $line->isProduct() ? 'product' : 'service',
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'line_total' => $line->line_total,
                // A product given without billing: used, off the shelf, not on the bill.
                'billed' => $line->isBilled(),
                'tax_exempt' => (bool) $line->tax_exempt,
                'notes' => $line->notes,
                'status' => $line->status->value,
                'order' => $order === null ? null : [
                    'uuid' => $order->uuid,
                    'title' => $order->title,
                    'type' => $order->type->value,
                    'status' => $order->status->value,
                ],
                'changeable' => $standing && $writable && $order !== null && $order->status !== OrderStatus::Cancelled,
            ];
            if ($standing) {
                $lines[] = $row;
            } else {
                $removed[] = $row;
            }
        }

        $cards = [];
        foreach (PatientCard::spendableBy($visit->patient_id)->orderByDesc('id')->get() as $card) {
            if ($card->status->canTransact() && ! $card->isExpired()) {
                $cards[] = ['uuid' => $card->uuid, 'balance' => (string) $card->balance, 'last4' => substr((string) $card->card_number, -4)];
            }
        }

        $manage = $user->can('billing.manage');
        $settings = app(HospitalSettings::class);

        return [
            'lines' => $lines,
            'removed_lines' => $removed,
            'totals' => $totals,
            // As agreed at the counter: a percentage keeps its rule, `amount` is what it is worth now.
            'discount' => [
                'type' => ($visit->discount_type ?? DiscountType::Amount)->value,
                'value' => (string) ($visit->discount_value ?? '0.00'),
                'reason' => $visit->discount_reason,
                'by' => $visit->discountedBy?->name,
                'amount' => $totals['discount'],
            ],
            'tax_label' => $settings->taxLabel(),
            // Where a product added here starts: Billed, or Not billed (a dental clinic, unless set).
            'products_billed' => $settings->productsBilledByDefault(),
            'invoice' => $invoice === null ? null : (new InvoiceResource($invoice))->resolve(),
            'unbilled' => $unbilled,
            // Once invoiced the bill is fixed: no adding, no discount, no rebilling.
            'can_add' => $manage && $invoice === null,
            'can_discount' => $manage && $user->can('billing.discount') && $invoice === null,
            'can_rebill' => $manage && $invoice === null,
            'can_invoice' => $invoice === null && $user->can('create', Invoice::class) && $lines !== [],
            'can_pay' => $invoice !== null && bccomp((string) $invoice->balance, '0', 2) > 0 && $user->can('pay', $invoice),
            'methods' => array_map(fn (PaymentMethod $m) => ['value' => $m->value, 'needs_reference' => $m->needsReference()], PaymentMethod::recordable()),
            'cards' => $cards,
        ];
    }

    /** Raise the invoice, with the discount agreed on the visit. */
    public function invoice(Request $request, Visit $visit): JsonResponse
    {
        $this->authorize('view', $visit);
        $this->authorize('create', Invoice::class);

        // The discount decided at the counter, not one typed here.
        $discount = $this->billing->discountFor($visit);

        try {
            $invoice = $this->billing->generateInvoice($visit, InvoiceGenerateRequest::normalise($discount), $request->user()->id);
        } catch (RuntimeException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422);
        }

        return ApiResponse::success((new InvoiceResource($invoice->load(['patient', 'items', 'payments'])))->resolve(), "Invoice {$invoice->invoice_no} raised.", 201);
    }

    public function pay(PaymentRequest $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('pay', $invoice);

        $data = $request->validated();
        $opts = ['reference' => filled($data['reference'] ?? null) ? trim((string) $data['reference']) : null];
        if (filled($data['card_uuid'] ?? null)) {
            $opts['card'] = PatientCard::where('uuid', $data['card_uuid'])->firstOrFail();
        }

        try {
            $payment = $this->billing->recordPayment($invoice, $request->paymentMethod(), $request->amountString(), $opts, $request->user()->id);
        } catch (RuntimeException $e) {
            // Overpayment, a closed period, a card with nothing on it.
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422, ['amount' => [$e->getMessage()]]);
        }

        return ApiResponse::success(
            (new InvoiceResource($invoice->fresh(['patient', 'items', 'payments.receivedBy'])))->resolve() + ['payment_uuid' => $payment->uuid],
            'Payment recorded.',
            201,
        );
    }
}
