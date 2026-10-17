<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SharedDocumentType;
use App\Http\Controllers\Controller;
use App\Models\Visit;
use App\Services\PatientLinks\SharedDocuments;

/**
 * A visit's prescription as a PDF, the same one a patient link opens
 * (roadmap 05 §4). Anyone who may read the visit's clinical record may print it.
 */
class PrescriptionPdfController extends Controller
{
    public function __invoke(Visit $visit, SharedDocuments $documents)
    {
        $this->authorize('view', $visit);
        $this->authorize('viewClinical', $visit);

        return $documents->pdf(SharedDocumentType::Prescription, $visit)
            ->stream($documents->filename(SharedDocumentType::Prescription, $visit));
    }
}
