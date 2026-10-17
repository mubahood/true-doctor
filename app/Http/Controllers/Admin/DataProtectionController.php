<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Hospital;
use App\Models\Patient;
use App\Services\Compliance\PersonalDataExport;
use App\Support\Compliance\ComplianceDocuments;
use App\Support\CurrentHospital;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The data protection pack as PDFs, and one patient's data for a request to
 * see it (docs/compliance/README.md). The full export's download is
 * DataExportController: a file to keep, not a document to read.
 */
class DataProtectionController extends Controller
{
    public function document(Request $request, string $document): Response
    {
        abort_unless($request->user()?->can('manage-settings'), 403);
        abort_unless(ComplianceDocuments::exists($document), 404);

        $id = app(CurrentHospital::class)->id();
        $hospital = $id !== null ? Hospital::query()->find($id) : null;

        return Pdf::loadView('pdf.compliance.document', [
            'title' => ComplianceDocuments::title($document),
            'html' => ComplianceDocuments::html($document, $hospital),
            'document' => $document,
        ])->setPaper('a4')->stream($document.'.pdf');
    }

    /** Everything held about one patient, for a request to see it (PDF to hand over, JSON to send on). */
    public function patient(Request $request, Patient $patient, string $format, PersonalDataExport $export): Response|JsonResponse
    {
        abort_unless($request->user()?->can('manage-settings'), 403);
        // The clinic's own patient only: a platform account is not the clinic answering a request.
        abort_unless($request->user()->hospital_id !== null && (int) $request->user()->hospital_id === (int) $patient->hospital_id, 404);

        $sections = $export->sections($patient);
        activity()->causedBy($request->user())->performedOn($patient)
            ->withProperties(['format' => $format])->log('patient data exported');

        $name = 'personal-data-'.$patient->patient_no;
        if ($format === 'json') {
            return response()->json([
                'patient' => $patient->full_name,
                'prepared_at' => now()->toIso8601String(),
                'sections' => $sections,
            ], 200, ['Content-Disposition' => 'attachment; filename="'.$name.'.json"'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        return Pdf::loadView('pdf.compliance.personal-data', [
            'patient' => $patient,
            'sections' => $sections,
        ])->setPaper('a4')->stream($name.'.pdf');
    }
}
