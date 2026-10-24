<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DiagnosisCode;
use App\Models\Visit;
use App\Models\VisitDiagnosis;
use App\Services\DiagnosisService;
use App\Support\ApiResponse;
use App\Support\CurrentHospital;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Coded diagnoses over the API (docs/api/diagnoses.md): the same service as the visit page. */
class DiagnosisController extends Controller
{
    public function __construct(private readonly DiagnosisService $diagnoses) {}

    /**
     * GET diagnoses/search?q= : the best matches, this practice's own first.
     * Whoever codes from the worklist searches too (a records officer does
     * not open visits): the catalogue is reference data, not a record.
     */
    public function search(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('visits.view') || $request->user()?->can('diagnoses.code'), 403);
        $q = (string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? '');

        return ApiResponse::success($this->diagnoses->search($q, app(CurrentHospital::class)->id(), 8)
            ->map(fn (DiagnosisCode $d) => ['code' => $d->code, 'title' => $d->title, 'system' => $d->system])->values());
    }

    /**
     * GET diagnoses/worklist?q= : the web's Diagnoses to code. Visits with a
     * written diagnosis and no code, newest first, each with the codes that
     * may fit its words (DiagnosisService::suggest). At most 50 a page: the
     * suggestions are worked out for every row.
     */
    public function worklist(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('diagnoses.code'), 403);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $perPage = min((int) ($data['per_page'] ?? 20), 50);
        $hospitalId = app(CurrentHospital::class)->id();

        $visits = $this->diagnoses->uncoded((string) ($data['q'] ?? ''))
            ->with(['patient:id,uuid,first_name,last_name,patient_no', 'doctor:id,name'])
            ->paginate($perPage);

        $rows = collect($visits->items())->map(fn (Visit $v) => [
            'uuid' => $v->uuid,
            'visit_no' => $v->visit_no,
            'created_at' => $v->created_at?->toIso8601String(),
            'patient' => $v->patient === null ? null : ['uuid' => $v->patient->uuid, 'name' => $v->patient->full_name, 'patient_no' => $v->patient->patient_no],
            'doctor' => $v->doctor?->name,
            'diagnosis' => $v->diagnosis,
            'suggestions' => $this->diagnoses->suggest((string) $v->diagnosis, $hospitalId)
                ->map(fn (DiagnosisCode $d) => ['code' => $d->code, 'title' => $d->title, 'system' => $d->system])->values()->all(),
        ])->values()->all();

        return ApiResponse::paginated($visits, $rows);
    }

    /**
     * POST diagnoses/worklist/{visit} {code, certainty?, uuid?} : code a visit
     * from the worklist, as the web's one press does. The first code is the
     * primary; a visit coded meanwhile is refused (422), so it leaves the list.
     */
    public function code(Request $request, Visit $visit): JsonResponse
    {
        abort_unless($request->user()?->can('diagnoses.code'), 403);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:10'],
            'certainty' => ['sometimes', 'in:confirmed,suspected'],
            'uuid' => ['nullable', 'uuid'],
        ]);

        $d = $this->diagnoses->codeFromWorklist($visit, $data['code'], $data['certainty'] ?? 'confirmed', $request->user(), $data['uuid'] ?? null);

        return ApiResponse::success($this->row($d), 'Coded.', 201);
    }

    /** GET visits/{visit}/diagnoses */
    public function index(Visit $visit): JsonResponse
    {
        $this->authorize('view', $visit);
        $this->authorize('viewClinical', $visit);

        return ApiResponse::success($this->diagnoses->current($visit)->map(fn (VisitDiagnosis $d) => $this->row($d))->values());
    }

    /** POST visits/{visit}/diagnoses {code, primary?, certainty?, note?, uuid?} */
    public function store(Request $request, Visit $visit): JsonResponse
    {
        $this->authorize('diagnose', $visit);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:10'],
            'primary' => ['sometimes', 'boolean'],
            'certainty' => ['sometimes', 'in:confirmed,suspected'],
            'note' => ['nullable', 'string', 'max:500'],
            'uuid' => ['nullable', 'uuid'],
        ]);
        $d = $this->diagnoses->add($visit, $data['code'], (bool) ($data['primary'] ?? false), $data['certainty'] ?? 'confirmed', $data['note'] ?? null, $request->user(), $data['uuid'] ?? null);

        return ApiResponse::success($this->row($d), 'Diagnosis recorded.', 201);
    }

    /** PATCH visits/{visit}/diagnoses/{id} {primary?, certainty?} */
    public function update(Request $request, Visit $visit, int $id): JsonResponse
    {
        $this->authorize('diagnose', $visit);
        $data = $request->validate(['primary' => ['sometimes', 'accepted'], 'certainty' => ['sometimes', 'in:confirmed,suspected']]);
        $d = $this->diagnoses->current($visit)->firstWhere('id', $id) ?? abort(404);

        return ApiResponse::success($this->row($this->diagnoses->change($d, isset($data['primary']) ? true : null, $data['certainty'] ?? null, $request->user())));
    }

    /** DELETE visits/{visit}/diagnoses/{id} */
    public function destroy(Request $request, Visit $visit, int $id): JsonResponse
    {
        $this->authorize('diagnose', $visit);
        $d = $this->diagnoses->current($visit)->firstWhere('id', $id) ?? abort(404);
        $this->diagnoses->remove($d, $request->user());

        return ApiResponse::success(null, 'Diagnosis removed.');
    }

    /** @return array<string,mixed> */
    private function row(VisitDiagnosis $d): array
    {
        return [
            'id' => $d->id, 'uuid' => $d->uuid, 'code' => $d->code, 'title' => $d->title, 'system' => $d->system,
            'primary' => $d->is_primary, 'certainty' => $d->certainty, 'note' => $d->note,
            'recorded_at' => $d->recorded_at->toIso8601String(),
        ];
    }
}
