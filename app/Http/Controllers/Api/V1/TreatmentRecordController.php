<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\TreatmentRecordRequest;
use App\Models\Patient;
use App\Models\TreatmentRecord;
use App\Models\TreatmentRecordItem;
use App\Services\TreatmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Treatment records with their photos over the API
 * (docs/api/patient-records.md): the web record's Treatment records panel.
 * Seeing them needs `treatments.view`, adding and removing
 * `treatments.manage` (TreatmentRecordPolicy). TreatmentService writes the
 * record and its photos together and files them on the private disk; the
 * photos stream through the web's TreatmentRecordController.
 */
class TreatmentRecordController extends Controller
{
    public function __construct(private readonly TreatmentService $service) {}

    /** GET patients/{patient}/treatments */
    public function index(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);
        $this->authorize('viewAny', TreatmentRecord::class);
        $user = $request->user();

        $rows = $patient->treatmentRecords()->with(['photos', 'performedBy:id,name'])->get()
            ->map(fn (TreatmentRecord $r) => $this->row($patient, $r) + ['can_delete' => $user->can('delete', $r)])->values();

        return ApiResponse::withMeta($rows, [
            'can_create' => $user->can('create', TreatmentRecord::class),
            // The request's own limits, so the app can say so before sending.
            'max_photos' => TreatmentRecordRequest::MAX_PHOTOS,
            'photo_max_kb' => TreatmentRecordRequest::PHOTO_MAX_KB,
        ]);
    }

    /** POST patients/{patient}/treatments (multipart: procedure, description?, performed_at?, photos[]?) */
    public function store(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);
        $this->authorize('create', TreatmentRecord::class);

        // As the panel: the record goes on the patient's current visit (one is
        // opened if none is), so the visit is not the caller's to choose.
        $rules = TreatmentRecordRequest::rulesFor();
        unset($rules['visit_id']);
        $data = $request->validate($rules);

        $record = $this->service->create(
            $patient,
            [
                'procedure' => $data['procedure'],
                'description' => filled($data['description'] ?? null) ? $data['description'] : null,
                'performed_at' => filled($data['performed_at'] ?? null) ? $data['performed_at'] : null,
            ],
            array_values((array) $request->file('photos', [])),
            $request->user()->id,
        );

        $record->load(['photos', 'performedBy:id,name']);

        return ApiResponse::success(
            $this->row($patient, $record) + ['can_delete' => $request->user()->can('delete', $record)],
            'Treatment record added.',
            201,
        );
    }

    /** DELETE patients/{patient}/treatments/{treatment} */
    public function destroy(Patient $patient, TreatmentRecord $treatment): JsonResponse
    {
        $this->authorize('view', $patient);
        abort_unless($treatment->patient_id === $patient->id, 404);
        $this->authorize('delete', $treatment);

        $this->service->delete($treatment->load('photos'));

        return ApiResponse::success(['uuid' => $treatment->uuid], 'Treatment record removed.');
    }

    /** @return array<string,mixed> */
    private function row(Patient $patient, TreatmentRecord $r): array
    {
        return [
            'uuid' => $r->uuid,
            'procedure' => $r->procedure,
            'description' => $r->description,
            'performed_at' => ($r->performed_at ?? $r->created_at)?->toIso8601String(),
            'performed_by' => $r->performedBy?->name,
            // The specialty's own fields (an eye exam, a physiotherapy session), as recorded.
            'meta' => $r->meta,
            'photos' => $r->photos->map(fn (TreatmentRecordItem $p) => [
                'id' => $p->id,
                'caption' => $p->caption,
                // Relative to /api/v1: the image, behind the same policy as the web's thumbnail.
                'path' => "patients/{$patient->uuid}/treatment-photos/{$p->id}",
            ])->values(),
        ];
    }
}
