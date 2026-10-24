<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PatientDocumentRequest;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Services\DocumentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A patient's documents over the API (docs/api/patient-records.md): the web
 * record's Documents panel, the same DocumentService and the same rules
 * (PatientDocumentRequest: the types, PDF/image/Word, 10 MB). Reading needs
 * the patient; uploading and removing need PatientPolicy@manageDocuments.
 * The file itself streams through the web's PatientDocumentController.
 */
class PatientDocumentController extends Controller
{
    public function __construct(private readonly DocumentService $service) {}

    /** GET patients/{patient}/documents */
    public function index(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $rows = $patient->documents()->with('uploader:id,name')->get()
            ->map(fn (PatientDocument $d) => $this->row($patient, $d))->values();

        return ApiResponse::withMeta($rows, [
            'can_manage' => $request->user()->can('manageDocuments', $patient),
            'types' => array_map(fn (string $t) => ['value' => $t, 'label' => PatientDocument::typeLabel($t)], PatientDocument::TYPES),
            // The request's own limits, so the app can say so before sending.
            'max_kb' => PatientDocumentRequest::MAX_KB,
            'accept' => PatientDocumentRequest::MIMES,
        ]);
    }

    /** POST patients/{patient}/documents (multipart: type, note?, file) */
    public function store(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('manageDocuments', $patient);
        $data = $request->validate(PatientDocumentRequest::rulesFor());

        $document = $this->service->store(
            $patient,
            $request->file('file'),
            $data['type'],
            filled($data['note'] ?? null) ? $data['note'] : null,
            $request->user()->id,
        );

        return ApiResponse::success($this->row($patient, $document->load('uploader:id,name')), 'Document uploaded.', 201);
    }

    /** DELETE patients/{patient}/documents/{document} */
    public function destroy(Patient $patient, PatientDocument $document): JsonResponse
    {
        $this->authorize('manageDocuments', $patient);
        abort_unless($document->patient_id === $patient->id, 404);

        $this->service->delete($document);

        return ApiResponse::success(['uuid' => $document->uuid], 'Document removed.');
    }

    /** @return array<string,mixed> */
    private function row(Patient $patient, PatientDocument $d): array
    {
        return [
            'uuid' => $d->uuid,
            'type' => $d->type,
            'type_label' => PatientDocument::typeLabel($d->type),
            'original_name' => $d->original_name,
            'mime' => $d->mime,
            'size' => (int) ($d->size ?? 0),
            'size_label' => number_format(($d->size ?? 0) / 1024, 0).' KB',
            'note' => $d->note,
            'uploaded_by' => $d->uploader?->name,
            'created_at' => $d->created_at?->toIso8601String(),
            // Relative to /api/v1: the bytes, behind the same policy as the web's link.
            'download_path' => "patients/{$patient->uuid}/documents/{$d->uuid}/download",
        ];
    }
}
