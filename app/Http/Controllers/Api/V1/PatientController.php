<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\PagesAndDeltas;
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
    use PagesAndDeltas;

    public function __construct(private readonly PatientService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Patient::class);

        $patients = $this->applyDelta(Patient::query()
            ->search($request->query('q'))
            ->when($request->filled('status'), fn ($qq) => $qq->where('status', $request->query('status')))
            ->latest(), $request)
            ->paginate($this->perPage($request));

        return $this->deltaPage($request, $patients, PatientResource::class);
    }

    public function store(PatientRequest $request): JsonResponse
    {
        $this->authorize('create', Patient::class);

        $messaging = $this->messaging($request);

        try {
            $patient = $this->service->register($request->validated(), $request->user()->id);
        } catch (\App\Exceptions\PlanLimitExceededException $e) {
            return ApiResponse::error(\App\Enums\ApiErrorCode::PlanLimit, $e->getMessage(), 403, null, null, false);
        }
        $this->recordMessaging($patient, $messaging, $request->user()->id);

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
        $messaging = $this->messaging($request);

        $this->service->update($patient, $request->validated());
        $this->recordMessaging($patient, $messaging, $request->user()->id);

        return ApiResponse::success(new PatientResource($patient->fresh()), 'Patient updated.');
    }

    /**
     * The messages the patient agreed to (roadmap 05 §2), sent as
     * `messaging: {sms_care, whatsapp_care, sms_marketing}`; only the keys
     * sent are recorded, so an app that does not ask records nothing.
     *
     * @return array<string,bool>
     */
    private function messaging(Request $request): array
    {
        $data = $request->validate([
            'messaging' => ['sometimes', 'nullable', 'array'],
            'messaging.sms_care' => ['sometimes', 'boolean'],
            'messaging.whatsapp_care' => ['sometimes', 'boolean'],
            'messaging.sms_marketing' => ['sometimes', 'boolean'],
        ]);

        $out = [];
        foreach ((array) ($data['messaging'] ?? []) as $key => $on) {
            $out[str_replace('_', '.', (string) $key)] = (bool) $on;
        }

        return $out;
    }

    /** @param  array<string,bool>  $answers */
    private function recordMessaging(Patient $patient, array $answers, int $by): void
    {
        if ($answers !== []) {
            app(\App\Services\Messaging\ConsentService::class)->record($patient, $answers, 'app', $by);
        }
    }

    public function destroy(Patient $patient): JsonResponse
    {
        $this->authorize('delete', $patient);

        $patient->delete();

        return ApiResponse::success(null, 'Patient archived.');
    }
}
