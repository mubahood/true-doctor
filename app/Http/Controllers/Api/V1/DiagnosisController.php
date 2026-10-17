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

    /** GET diagnoses/search?q= : the five best matches, this practice's own first. */
    public function search(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('visits.view'), 403);
        $q = (string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? '');

        return ApiResponse::success($this->diagnoses->search($q, app(CurrentHospital::class)->id(), 8)
            ->map(fn (DiagnosisCode $d) => ['code' => $d->code, 'title' => $d->title, 'system' => $d->system])->values());
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
