<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\PatientDependentRequest;
use App\Models\Patient;
use App\Models\PatientDependent;
use App\Services\PatientService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Guardian and dependent links over the API (docs/api/patient-records.md):
 * the web record's Dependents panel. Reading needs the patient; linking and
 * unlinking PatientPolicy@manageDependents. PatientService refuses a
 * self-link and a duplicate, and the uuid must be this hospital's patient
 * (PatientDependentRequest scopes its `exists` rule).
 */
class PatientDependentController extends Controller
{
    public function __construct(private readonly PatientService $patients) {}

    /** GET patients/{patient}/dependents */
    public function index(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        return ApiResponse::success([
            'dependents' => $patient->dependents()->with('dependent')->get()
                ->map(fn (PatientDependent $l) => $this->row($l, $l->dependent))->values(),
            'guardians' => $patient->guardianLinks()->with('guardian')->get()
                ->map(fn (PatientDependent $l) => $this->row($l, $l->guardian))->values(),
            'relationships' => array_map(fn (string $r) => ['value' => $r, 'label' => ucfirst($r)], PatientDependent::RELATIONSHIPS),
            'can_manage' => $request->user()->can('manageDependents', $patient),
        ]);
    }

    /** POST patients/{patient}/dependents {dependent_uuid, relationship} */
    public function store(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('manageDependents', $patient);
        $data = $request->validate(PatientDependentRequest::rulesFor());

        try {
            $link = $this->patients->linkDependent($patient, $data['dependent_uuid'], $data['relationship']);
        } catch (DomainException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422, ['dependent_uuid' => [$e->getMessage()]]);
        }

        return ApiResponse::success($this->row($link, $link->dependent), "{$link->dependent->full_name} linked as {$data['relationship']}.", 201);
    }

    /** DELETE patients/{patient}/dependents/{link} - a link where this patient is the guardian. */
    public function destroy(Patient $patient, int $link): JsonResponse
    {
        $this->authorize('manageDependents', $patient);

        $this->patients->unlinkDependent($patient, $link);

        return ApiResponse::success(['id' => $link], 'Dependent unlinked.');
    }

    /** @return array<string,mixed> */
    private function row(PatientDependent $link, ?Patient $other): array
    {
        return [
            'id' => $link->id,
            'relationship' => $link->relationship,
            'relationship_label' => ucfirst((string) $link->relationship),
            // Null when the other record has been archived since.
            'patient' => $other === null ? null : [
                'uuid' => $other->uuid,
                'name' => $other->full_name,
                'patient_no' => $other->patient_no,
                'age' => $other->age(),
                'sex' => $other->sex?->value,
            ],
        ];
    }
}
