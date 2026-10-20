<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Visit;
use App\Services\DentalCaseSheetService;
use App\Services\DentalNotesService;
use App\Support\ApiResponse;
use App\Support\HospitalSettings;
use App\Support\Practice\VisitForm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A dental visit's simple notes for the app (docs/api/dental.md, "Simple
 * notes"): the same DentalNotesService as the web's visit page and new visit
 * form. Reading needs what the case sheet needs; writing needs `visits.diagnose`.
 */
class DentalNotesController extends Controller
{
    public function __construct(private readonly DentalNotesService $notes) {}

    /** GET visits/{visit}/dental-notes */
    public function show(Request $request, Visit $visit): JsonResponse
    {
        $this->read($request, $visit);

        return ApiResponse::success($this->payload($visit));
    }

    /** PUT visits/{visit}/dental-notes - `notes` {the eight fields}, `complaints?`, `base_version?`, `uuid?`. */
    public function update(Request $request, Visit $visit): JsonResponse
    {
        $this->read($request, $visit);
        $this->authorize('diagnose', $visit);

        $data = $request->validate([
            'notes' => ['present', 'array'],
            'notes.*' => ['nullable', 'string', 'max:'.DentalNotesService::MAX],
            'complaints' => ['sometimes', 'nullable', 'string', 'max:'.DentalNotesService::MAX],
            'base_version' => ['nullable', 'integer', 'min:0'],
            'uuid' => ['nullable', 'uuid'],
        ]);

        $input = $data['notes'];
        if ($request->has('complaints')) {
            $input['complaints'] = $data['complaints'] ?? '';
        }

        /** @var User $user */
        $user = $request->user();
        $this->notes->save($visit, $input, $user, isset($data['base_version']) ? (int) $data['base_version'] : null, $data['uuid'] ?? null);

        return ApiResponse::success($this->payload($visit->fresh() ?? $visit), 'Notes saved.');
    }

    private function read(Request $request, Visit $visit): void
    {
        $this->authorize('view', $visit);
        $this->authorize('viewClinical', $visit);
        abort_unless(DentalCaseSheetService::canRead($request->user()), 403, 'Your role cannot do that.');
    }

    /** @return array<string,mixed> */
    private function payload(Visit $visit): array
    {
        $hospital = app(HospitalSettings::class)->hospital();
        $notes = $this->notes->forVisit($visit);
        $data = [];
        foreach (array_keys(DentalNotesService::FIELDS) as $key) {
            $data[$key] = $notes['data'][$key] ?? null;
        }

        return [
            'visit_uuid' => $visit->uuid,
            // Whether this visit is written as simple notes (else the detailed case sheet).
            'applies' => DentalNotesService::appliesTo($visit, $hospital),
            'mode' => DentalNotesService::appliesTo($visit, $hospital) ? VisitForm::SIMPLE : VisitForm::DETAILED,
            'complaints' => $visit->complaints,
            'reason' => $visit->reason,
            'diagnosis' => $visit->diagnosis,
            'notes' => $data,
            'version' => $notes['version'],
            'recorded_at' => $notes['recorded_at'],
            'recorded_by' => $notes['recorded_by'],
            'carried' => $notes['carried'],
            'groups' => DentalNotesService::GROUPS,
            'fields' => array_map(fn (string $key, array $f) => ['key' => $key] + $f, array_keys(DentalNotesService::FIELDS), DentalNotesService::FIELDS),
        ];
    }
}
