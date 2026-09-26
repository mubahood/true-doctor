<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Enums\InvoiceStatus;
use App\Enums\OrderItemStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\InvoiceGenerateRequest;
use App\Http\Requests\PaymentRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\OrderItem;
use App\Models\PatientCard;
use App\Models\Visit;
use App\Services\BillingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A visit's bill (web: the visit's Charges and Payments panels): the lines,
 * the totals by BillingService's arithmetic, the invoice and what is still
 * unbilled; raising the invoice; taking a payment with PaymentRequest.
 */
class BillingController extends Controller
{
    public function __construct(private readonly BillingService $billing) {}

    public function bill(Request $request, Visit $visit): JsonResponse
    {
        $this->authorize('view', $visit);
        abort_unless($request->user()->can('billing.view') || $request->user()->can('billing.manage'), 403);

        $invoice = $visit->invoices()->where('status', '!=', InvoiceStatus::Void->value)->with(['patient', 'items', 'payments.receivedBy'])->first();
        $totals = $this->billing->totalsFor($visit);

        // Work charged after the invoice was raised (a bed charge at discharge,
        // say) — the invoice snapshots its lines; the bill keeps adding up.
        $unbilled = '0.00';
        if ($invoice !== null) {
            $gap = bcsub($totals['due'], (string) $invoice->total, 2);
            $unbilled = bccomp($gap, '0', 2) > 0 ? $gap : '0.00';
        }

        $lines = [];
        foreach (OrderItem::whereHas('order', fn ($q) => $q->where('visit_id', $visit->id))
            ->where('status', '!=', OrderItemStatus::Cancelled->value)->orderBy('id')->get() as $line) {
            $lines[] = ['name' => $line->name, 'quantity' => $line->quantity, 'unit_price' => $line->unit_price, 'line_total' => $line->line_total];
        }

        $cards = [];
        foreach (PatientCard::spendableBy($visit->patient_id)->orderByDesc('id')->get() as $card) {
            if ($card->status->canTransact() && ! $card->isExpired()) {
                $cards[] = ['uuid' => $card->uuid, 'balance' => (string) $card->balance, 'last4' => substr((string) $card->card_number, -4)];
            }
        }

        return ApiResponse::success([
            'lines' => $lines,
            'totals' => $totals,
            'invoice' => $invoice === null ? null : (new InvoiceResource($invoice))->resolve(),
            'unbilled' => $unbilled,
            'can_invoice' => $invoice === null && $request->user()->can('create', Invoice::class) && $lines !== [],
            'can_pay' => $invoice !== null && bccomp((string) $invoice->balance, '0', 2) > 0 && $request->user()->can('pay', $invoice),
            'methods' => array_map(fn (PaymentMethod $m) => ['value' => $m->value, 'needs_reference' => $m->needsReference()], PaymentMethod::recordable()),
            'cards' => $cards,
        ]);
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
