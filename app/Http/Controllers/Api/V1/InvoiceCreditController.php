<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Enums\CreditKind;
use App\Enums\CreditStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\InvoiceCreditRequest;
use App\Http\Resources\InvoiceCreditResource;
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
    private const WITH = ['invoice', 'patient', 'requestedBy:id,name', 'decidedBy:id,name'];

    public function __construct(private readonly InvoiceCreditService $credits) {}

    /** GET credits?status=&kind=&q= : every hospital-wide, newest first (the approver's list). */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InvoiceCredit::class);
        $f = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', array_keys(CreditStatus::options()))],
            'kind' => ['nullable', 'in:'.implode(',', array_keys(CreditKind::options()))],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = InvoiceCredit::query()->with(self::WITH)
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))
            ->matching($f['q'] ?? null)
            ->latest('requested_at')->latest('id')
            ->paginate((int) ($f['per_page'] ?? 25));

        return ApiResponse::paginated($page, InvoiceCreditResource::collection($page->getCollection())->resolve($request), [
            'pending' => InvoiceCredit::query()->where('status', CreditStatus::Pending->value)->count(),
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

        return [
            'invoice' => ['uuid' => $invoice->uuid, 'invoice_no' => $invoice->invoice_no, 'status' => $invoice->status->value],
            'figures' => $this->credits->figures($invoice),
            'can_request' => (bool) $request->user()?->can('credit', $invoice),
            'can_approve' => (bool) $request->user()?->can('billing.refund'),
            'refund_methods' => InvoiceCreditService::REFUND_METHODS,
            'credits' => InvoiceCreditResource::collection($credits)->resolve($request),
        ];
    }
}
