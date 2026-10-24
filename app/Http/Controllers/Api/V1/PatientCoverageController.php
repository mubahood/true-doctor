<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PatientInsuranceRequest;
use App\Models\InsuranceClaim;
use App\Models\InsuranceProvider;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Services\PatientService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A patient's insurance cover over the API (docs/api/patient-records.md):
 * the web record's Insurance coverage panel. Seeing it needs `insurance.view`
 * (InsuranceClaimPolicy@viewAny), adding and removing `insurance.manage`,
 * exactly as the panel asks; the writes are PatientService's.
 */
class PatientCoverageController extends Controller
{
    public function __construct(private readonly PatientService $patients) {}

    /** GET patients/{patient}/insurances */
    public function index(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);
        $this->authorize('viewAny', InsuranceClaim::class);
        $canManage = $request->user()->can('manage', InsuranceClaim::class);

        $rows = $patient->insurances()->with('provider:id,name')->get()
            ->map(fn (PatientInsurance $c) => $this->row($c))->values();

        return ApiResponse::withMeta($rows, [
            'can_manage' => $canManage,
            // The add form's choices, as the panel offers them: active insurers only.
            'providers' => $canManage
                ? InsuranceProvider::where('is_active', true)->orderBy('name')->get(['id', 'name'])
                    ->map(fn (InsuranceProvider $p) => ['id' => $p->id, 'name' => $p->name])->values()
                : [],
        ]);
    }

    /** POST patients/{patient}/insurances */
    public function store(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);
        $this->authorize('manage', InsuranceClaim::class);

        $data = $request->validate(PatientInsuranceRequest::rulesFor());
        // Active unless said otherwise, as the panel's box starts ticked.
        $data['is_active'] = $request->has('is_active') ? $request->boolean('is_active') : true;
        $data['valid_from'] = filled($data['valid_from'] ?? null) ? $data['valid_from'] : null;
        $data['valid_to'] = filled($data['valid_to'] ?? null) ? $data['valid_to'] : null;

        $coverage = $this->patients->addInsurance($patient, $data);

        return ApiResponse::success($this->row($coverage->load('provider:id,name')), 'Insurance coverage added.', 201);
    }

    /** DELETE patients/{patient}/insurances/{insurance} - a row of this patient's only. */
    public function destroy(Patient $patient, int $insurance): JsonResponse
    {
        $this->authorize('view', $patient);
        $this->authorize('manage', InsuranceClaim::class);

        $this->patients->removeInsurance($patient, $insurance);

        return ApiResponse::success(['id' => $insurance], 'Coverage removed.');
    }

    /** @return array<string,mixed> */
    private function row(PatientInsurance $c): array
    {
        $percent = (string) $c->coverage_percent;

        return [
            'id' => $c->id,
            'provider' => $c->provider === null ? null : ['id' => $c->provider->id, 'name' => $c->provider->name],
            'member_no' => $c->member_no,
            'coverage_percent' => $percent,
            // "80%", "12.5%": as the panel's badge reads.
            'coverage_label' => rtrim(rtrim($percent, '0'), '.').'%',
            'valid_from' => $c->valid_from?->toDateString(),
            'valid_to' => $c->valid_to?->toDateString(),
            'is_active' => (bool) $c->is_active,
            // Active and not past its end date (PatientInsurance::isValid), the panel's "Expired" badge.
            'valid' => $c->isValid(),
        ];
    }
}
