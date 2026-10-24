<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Enums\CreditKind;
use App\Enums\CreditStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\InvoiceCreditRequest;
use App\Http\Resources\InvoiceCreditResource;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\InvoiceCredit;
use App\Models\PatientCard;
use App\Services\InvoiceCreditService;
use App\Support\ApiResponse;
use App\Support\HospitalSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Credit notes and refunds over the API (docs/api/refunds.md): the same
 * service and policies as the invoice page. Every write is covered by the
 * Idempotency-Key middleware, so a retried refund after a lost reply returns
 * the first answer instead of paying twice.
 */
class InvoiceCreditController extends Controller
{
    private const WITH = ['invoice', 'patient', 'requestedBy:id,name', 'decidedBy:id,name', 'card'];

    public function __construct(private readonly InvoiceCreditService $credits) {}

    /**
     * GET credits?status=&kind=&q=&waiting_first= : every hospital-wide, newest
     * first (the approver's list). `waiting_first=1` puts the ones waiting for
     * approval at the top, still newest first within each part (the app).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InvoiceCredit::class);
        $f = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', array_keys(CreditStatus::options()))],
            'kind' => ['nullable', 'in:'.implode(',', array_keys(CreditKind::options()))],
            'q' => ['nullable', 'string', 'max:100'],
            'waiting_first' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = InvoiceCredit::query()->with(self::WITH)
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))
            ->matching($f['q'] ?? null)
            ->when($request->boolean('waiting_first'), fn ($q) => $q->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [CreditStatus::Pending->value]))
            ->latest('requested_at')->latest('id')
            ->paginate((int) ($f['per_page'] ?? 25));

        return ApiResponse::paginated($page, InvoiceCreditResource::collection($page->getCollection())->resolve($request), [
            'pending' => InvoiceCredit::query()->where('status', CreditStatus::Pending->value)->count(),
        ]);
    }

    /**
     * GET credits/{credit} : one document with its invoice, the invoice's
     * lines and what can still be credited or refunded on it (the web's quick
     * view, and what the approver looks at before deciding).
     */
    public function show(Request $request, InvoiceCredit $credit): JsonResponse
    {
        $this->authorize('view', $credit);
        $credit->load(self::WITH);
        $invoice = $credit->invoice;

        return ApiResponse::success([
            'credit' => (new InvoiceCreditResource($credit))->resolve($request),
            'invoice' => $invoice === null ? null : (new InvoiceResource($invoice->loadMissing(['patient', 'items'])))->resolve($request),
            'figures' => $invoice === null ? null : $this->credits->figures($invoice),
            'can_approve' => $credit->isPending() && (bool) $request->user()?->can('approve', $credit),
        ]);
    }

    /** GET invoices/{invoice}/credits : the invoice's documents and what is left to credit or refund. */
    public function forInvoice(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);

        return ApiResponse::success($this->summary($request, $invoice));
    }

    /** POST invoices/{invoice}/credit-notes {amount, reason} */
    public function creditNote(InvoiceCreditRequest $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('credit', $invoice);
        $data = $request->validated();

        return $this->answer($request, $invoice, fn () => $this->credits->requestCreditNote(
            $invoice, HospitalSettings::decimal($data['amount'], 2), (string) $data['reason'], $request->user(),
        ));
    }

    /** POST invoices/{invoice}/refunds {amount, method, reason, reference?, card_uuid?} */
    public function refund(InvoiceCreditRequest $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('credit', $invoice);
        $data = $request->validated();
        $card = filled($data['card_uuid'] ?? null) ? PatientCard::query()->where('uuid', $data['card_uuid'])->firstOrFail() : null;

        return $this->answer($request, $invoice, fn () => $this->credits->requestRefund(
            $invoice, PaymentMethod::from($data['method']), HospitalSettings::decimal($data['amount'], 2),
            (string) $data['reason'], $request->user(), $data['reference'] ?? null, $card,
        ));
    }

    /** POST credits/{credit}/approve */
    public function approve(Request $request, InvoiceCredit $credit): JsonResponse
    {
        $this->authorize('approve', $credit);

        try {
            $credit = $this->credits->approve($credit, $request->user());
        } catch (RuntimeException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422);
        }

        return ApiResponse::success((new InvoiceCreditResource($credit->load(self::WITH)))->resolve($request), "{$credit->kind->label()} {$credit->number} approved.");
    }

    /** POST credits/{credit}/reject {note} */
    public function reject(Request $request, InvoiceCredit $credit): JsonResponse
    {
        $this->authorize('approve', $credit);
        $note = (string) $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']])['note'];

        try {
            $credit = $this->credits->reject($credit, $note, $request->user());
        } catch (RuntimeException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422);
        }

        return ApiResponse::success((new InvoiceCreditResource($credit->load(self::WITH)))->resolve($request), 'Rejected.');
    }

    // ── Inside ───────────────────────────────────────────────────────────

    /** @param \Closure(): InvoiceCredit $work */
    private function answer(Request $request, Invoice $invoice, \Closure $work): JsonResponse
    {
        try {
            $credit = $work();
        } catch (RuntimeException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422, ['amount' => [$e->getMessage()]]);
        }

        $message = $credit->isPending()
            ? "{$credit->kind->label()} sent for approval."
            : "{$credit->kind->label()} {$credit->number} approved.";

        return ApiResponse::success(
            ['credit' => (new InvoiceCreditResource($credit->load(self::WITH)))->resolve($request)] + $this->summary($request, $invoice->fresh()),
            $message,
            201,
        );
    }

    /** @return array<string,mixed> */
    private function summary(Request $request, Invoice $invoice): array
    {
        $credits = $invoice->credits()->with(self::WITH)->get();
        $canRequest = (bool) $request->user()?->can('credit', $invoice);

        return [
            'invoice' => ['uuid' => $invoice->uuid, 'invoice_no' => $invoice->invoice_no, 'status' => $invoice->status->value],
            'figures' => $this->credits->figures($invoice),
            'can_request' => $canRequest,
            'can_approve' => (bool) $request->user()?->can('billing.refund'),
            'refund_methods' => InvoiceCreditService::REFUND_METHODS,
            // The same methods with their words, and whether each needs a reference (the app's form).
            'refund_method_options' => array_map(fn (string $m) => [
                'value' => $m,
                'label' => PaymentMethod::from($m)->label(),
                'needs_reference' => PaymentMethod::from($m)->needsReference(),
            ], InvoiceCreditService::REFUND_METHODS),
            // The patient's prepaid cards, for a refund back onto one: the web's refund dialog lists the same.
            'cards' => $canRequest
                ? ($invoice->patient?->cards()->orderByDesc('id')->get() ?? collect())
                    ->map(fn (PatientCard $c) => ['uuid' => $c->uuid, 'masked' => $c->masked(), 'balance' => (string) $c->balance])->values()->all()
                : [],
            'credits' => InvoiceCreditResource::collection($credits)->resolve($request),
        ];
    }
}
