<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Services\DocumentService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams one patient document. Files never sit on a public disk; the download
 * goes through here so the Policy (and the tenant scope) gate every read.
 * Uploading and removing documents is App\Livewire\Patients\Panels\Documents.
 */
class PatientDocumentController extends Controller
{
    public function __construct(private readonly DocumentService $service) {}

    public function download(Patient $patient, PatientDocument $document): StreamedResponse
    {
        $this->authorize('view', $patient);
        abort_unless($document->patient_id === $patient->id, 404);
        abort_unless($this->service->disk()->exists($document->file_path), 404);

        return $this->service->disk()->download($document->file_path, $document->original_name);
    }
}
