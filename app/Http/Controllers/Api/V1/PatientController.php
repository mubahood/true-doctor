<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PatientRequest;
use App\Http\Resources\PatientResource;
use App\Models\Patient;
use App\Services\PatientService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API patients. Same PatientService + PatientPolicy as the admin panel, so the
 * two surfaces can't drift (C13). Tenancy is enforced by the global scope;
 * implicit binding resolves by uuid, already tenant-scoped.
 */
class PatientController extends Controller
{
    public function __construct(private readonly PatientService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Patient::class);

        $patients = Patient::query()
            ->search($request->query('q'))
            ->when($request->filled('status'), fn ($qq) => $qq->where('status', $request->query('status')))
            ->latest()
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return ApiResponse::paginated($patients, PatientResource::collection($patients->items()));
    }

    public function store(PatientRequest $request): JsonResponse
    {
        $this->authorize('create', Patient::class);

        try {
            $patient = $this->service->register($request->validated(), $request->user()->id);
        } catch (\App\Exceptions\PlanLimitExceededException $e) {
            return ApiResponse::error(\App\Enums\ApiErrorCode::Forbidden, $e->getMessage(), 403);
        }

        return ApiResponse::success(new PatientResource($patient), 'Patient registered.', 201);
    }

    public function show(Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        return ApiResponse::success(new PatientResource($patient));
    }

    /**
     * What the desk should know before opening a visit for this patient — an
     * open visit, today's booking, money owed. The web dialog's brief.
     */
    public function brief(Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $brief = \App\Support\PatientBrief::for($patient);

        return ApiResponse::success($brief + ['owed_label' => \App\Support\HospitalSettings::money($brief['owed'])]);
    }

    public function update(PatientRequest $request, Patient $patient): JsonResponse
    {
        $this->authorize('update', $patient);

        $this->service->update($patient, $request->validated());

        return ApiResponse::success(new PatientResource($patient->fresh()), 'Patient updated.');
    }

    public function destroy(Patient $patient): JsonResponse
    {
        $this->authorize('delete', $patient);

        $patient->delete();

        return ApiResponse::success(null, 'Patient archived.');
    }
}
