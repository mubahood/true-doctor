<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AdmissionStatus;
use App\Enums\ApiErrorCode;
use App\Exceptions\BedUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdmissionRequest;
use App\Http\Requests\BedTransferRequest;
use App\Http\Requests\DischargeRequest;
use App\Http\Resources\AdmissionResource;
use App\Http\Resources\NursingEntryResource;
use App\Models\Admission;
use App\Models\Bed;
use App\Models\Visit;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Moving inpatients — the web's transfer and discharge dialogs, through the
 * same AdmissionService and requests (manage Admission). Also the admissions
 * list/show and the admit dialog of the web Admissions page (view / manage
 * Admission), and the bed list with whoever is in each bed.
 */
class AdmissionController extends Controller
{
    public function __construct(private readonly AdmissionService $service) {}

    /** Wards and their beds as they are now — occupancy is live, not a device's copy. */
    public function beds(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('ipd.view') || $request->user()->can('ipd.manage'), 403);

        $out = [];
        foreach (Ward::where('is_active', true)->with(['beds' => fn ($q) => $q->where('is_active', true)->orderBy('name'), 'beds.currentAdmission.patient'])->orderBy('name')->get() as $ward) {
            $beds = [];
            foreach ($ward->beds as $bed) {
                $adm = $bed->currentAdmission;
                $beds[] = ['id' => $bed->id, 'name' => $bed->name, 'status' => $bed->status->value, 'daily_charge' => (string) $bed->daily_charge,
                    'occupant' => $adm === null ? null : [
                        'admission_uuid' => $adm->uuid, 'patient_name' => $adm->patient?->full_name,
                        'patient_no' => $adm->patient?->patient_no, 'admitted_at' => $adm->admitted_at->toIso8601String(),
                    ]];
            }
            $out[] = ['id' => $ward->id, 'name' => $ward->name, 'beds' => $beds];
        }

        return ApiResponse::success($out);
    }

    /** The Admissions page: the admitted cohort by default, or any status; by ward; by patient. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Admission::class);

        $f = $request->validate([
            'status' => ['nullable', Rule::in(array_column(AdmissionStatus::cases(), 'value'))],
            'ward' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $q = trim((string) ($f['q'] ?? ''));

        $page = Admission::with(['patient', 'bed.ward', 'admittingDoctor', 'visit'])
            ->where('status', $f['status'] ?? AdmissionStatus::Admitted->value)
            ->when(filled($f['ward'] ?? null), fn (Builder $x) => $x->whereHas('bed', fn (Builder $b) => $b->where('ward_id', (int) $f['ward'])))
            ->when($q !== '', fn (Builder $x) => $x->whereHas('patient', fn (Builder $p) => $p->where(fn (Builder $w) => $w
                ->where('first_name', 'like', "%{$q}%")->orWhere('last_name', 'like', "%{$q}%")->orWhere('patient_no', 'like', "%{$q}%"))))
            ->latest('admitted_at')
            ->paginate((int) ($f['per_page'] ?? 20));

        return ApiResponse::paginated($page, AdmissionResource::collection($page->items()));
    }

    /** The workspace's read-only column plus a bedside summary and the bed moves. */
    public function show(Admission $admission): JsonResponse
    {
        $this->authorize('view', $admission);

        $admission->load(['patient', 'bed.ward', 'admittingDoctor', 'visit', 'transfers.fromBed', 'transfers.toBed']);
        $entry = fn ($row) => (new NursingEntryResource($row))->resolve();

        $data = (new AdmissionResource($admission))->resolve() + [
            'transfers' => $admission->transfers->map(fn ($t) => [
                'at' => $t->created_at?->toIso8601String(), 'from' => $t->fromBed?->name, 'to' => $t->toBed?->name, 'reason' => $t->reason,
            ])->values(),
            'bedside' => [
                'latest_vitals' => ($v = $admission->vitalRounds()->with('recordedBy')->first()) ? $entry($v) : null,
                'vitals_count' => $admission->vitalRounds()->count(),
                'medication_count' => $admission->medications()->count(),
                'recent_medication' => $admission->medications()->with('administeredBy')->limit(5)->get()->map($entry)->values(),
                'nursing_notes_count' => $admission->nursingNotes()->count(),
                'recent_notes' => $admission->nursingNotes()->with('recordedBy')->limit(3)->get()->map($entry)->values(),
            ],
            // The charges themselves are on the visit's bill (GET /visits/{uuid}/bill).
            'can_manage' => request()->user()->can('manage', Admission::class),
        ];

        return ApiResponse::success($data);
    }

    /**
     * Admit onto a visit, into a bed — the Admissions page's dialog, the same
     * rules (AdmissionRequest) and AdmissionService::admit.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('manage', Admission::class);

        $data = $request->validate(AdmissionRequest::rulesFor() + ['stay_title' => ['nullable', 'string', 'max:120']]);
        $bed = Bed::findOrFail($data['bed_id']);
        $visit = Visit::with('patient')->findOrFail($data['visit_id']);

        if ($visit->patient === null) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, 'That visit has no patient on it.', 422, ['visit_id' => ['That visit has no patient on it.']]);
        }

        try {
            $admission = $this->service->admit($visit->patient, $bed, [
                'visit_id' => $visit->id,
                'admitting_doctor_id' => $data['admitting_doctor_id'] ?? null,
                'reason' => ($data['reason'] ?? null) ?: null,
                'title' => ($data['stay_title'] ?? null) ?: null,
            ], $request->user()->id);
        } catch (BedUnavailableException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422, ['bed_id' => [$e->getMessage()]]);
        } catch (\DomainException|RuntimeException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422, ['visit_id' => [$e->getMessage()]]);
        }

        return ApiResponse::success(new AdmissionResource($admission->load(['patient', 'bed.ward', 'admittingDoctor', 'visit'])),
            $visit->patient->full_name.' admitted to '.$bed->name.'.', 201);
    }

    public function transfer(Request $request, Admission $admission): JsonResponse
    {
        $this->authorize('manage', Admission::class);

        $data = $request->validate(BedTransferRequest::rulesFor());
        $bed = Bed::findOrFail($data['to_bed_id']);

        try {
            $this->service->transfer($admission, $bed, ($data['reason'] ?? null) ?: null, $request->user()->id);
        } catch (RuntimeException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422, ['to_bed_id' => [$e->getMessage()]]);
        }

        return ApiResponse::success(['bed' => $bed->name], 'Patient transferred to '.$bed->name.'.');
    }

    public function discharge(Request $request, Admission $admission): JsonResponse
    {
        $this->authorize('manage', Admission::class);

        $data = $request->validate(DischargeRequest::rulesFor());

        try {
            $this->service->discharge($admission, AdmissionStatus::from($data['outcome']), ($data['discharge_notes'] ?? null) ?: null, $request->user()->id);
        } catch (RuntimeException $e) {
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), 422, ['outcome' => [$e->getMessage()]]);
        }

        return ApiResponse::success(['status' => $data['outcome']], 'Admission closed.');
    }
}
