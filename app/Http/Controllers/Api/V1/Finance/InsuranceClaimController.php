<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Enums\ClaimStatus;
use App\Http\Controllers\Api\V1\Concerns\AnswersFinanceRequests;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ClaimApiRequest;
use App\Http\Requests\Api\ClaimTransitionRequest;
use App\Http\Resources\InsuranceClaimResource;
use App\Models\InsuranceClaim;
use App\Models\Invoice;
use App\Models\Patient;
use App\Services\InsuranceService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Insurance claims (web: admin.insurance-claims.*), by InsuranceClaimPolicy:
 * read = insurance.view, raise/move = insurance.manage. The lifecycle is
 * ClaimStatus's machine driven through InsuranceService; reaching Paid records
 * an insurance payment on the linked invoice.
 */
class InsuranceClaimController extends Controller
{
    use AnswersFinanceRequests;

    /** insurance.view. ?status=draft|submitted|approved|rejected|paid|cancelled, ?q= (claim no, patient name/no). */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InsuranceClaim::class);

        $status = (string) $request->query('status', '');
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);

        $rows = InsuranceClaim::with(['patient', 'provider'])
            ->when(ClaimStatus::tryFrom($status), fn ($qq) => $qq->where('status', $status))
            ->when($q !== '', fn ($qq) => $qq->where(fn ($w) => $w
                ->where('claim_no', 'like', "%{$q}%")
                ->orWhereHas('patient', fn ($p) => $p->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhere('patient_no', 'like', "%{$q}%"))))
            ->latest()
            ->paginate($this->perPage($request));

        return ApiResponse::paginated($rows, InsuranceClaimResource::collection($rows->getCollection())->resolve(), ['statuses' => ClaimStatus::options()]);
    }

    /** insurance.view. */
    public function show(InsuranceClaim $insuranceClaim): JsonResponse
    {
        $this->authorize('view', $insuranceClaim);

        return ApiResponse::success((new InsuranceClaimResource($insuranceClaim->load(['patient', 'provider', 'invoice', 'resolvedBy'])))->resolve());
    }

    /** insurance.manage. {patient_uuid, invoice_uuid, insurance_provider_id, amount, notes?} -> a Draft claim. */
    public function store(ClaimApiRequest $request, InsuranceService $service): JsonResponse
    {
        $this->authorize('manage', InsuranceClaim::class);

        $data = $request->validated();
        $data['patient_id'] = Patient::where('uuid', $data['patient_uuid'])->value('id');
        $data['invoice_id'] = Invoice::where('uuid', $data['invoice_uuid'])->value('id');
        $data['notes'] = ($data['notes'] ?? '') === '' ? null : $data['notes'];

        try {
            $claim = $service->createClaim($data, $request->user()->id);
        } catch (\DomainException|RuntimeException $e) {
            return $this->refuse($e);
        }

        return ApiResponse::success((new InsuranceClaimResource($claim->load(['patient', 'provider', 'invoice'])))->resolve(), "Claim {$claim->claim_no} created.", 201);
    }

    /** insurance.manage. {status, note?}: submitted, approved, rejected, paid (records the payment on the invoice), cancelled. Illegal moves are 422. */
    public function transition(ClaimTransitionRequest $request, InsuranceClaim $insuranceClaim, InsuranceService $service): JsonResponse
    {
        $this->authorize('manage', InsuranceClaim::class);

        $to = ClaimStatus::from($request->validated('status'));

        try {
            $claim = $service->transition($insuranceClaim, $to, $request->user()->id, ($request->validated('note') ?? null) ?: null);
        } catch (RuntimeException $e) {
            return $this->refuse($e, 'status');
        }

        return ApiResponse::success((new InsuranceClaimResource($claim->load(['patient', 'provider', 'invoice', 'resolvedBy'])))->resolve(), "Claim moved to {$to->label()}.");
    }
}
