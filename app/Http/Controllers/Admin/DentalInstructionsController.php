<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Visit;
use App\Services\DentalCaseSheetService;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * The instructions after treatment as a PDF for the patient: what was done,
 * the instructions ticked on the dental case sheet and when to come back.
 * The case sheet's Print button and the app (`api.dental.case-sheet.instructions`).
 */
class DentalInstructionsController extends Controller
{
    public function __invoke(Visit $visit, DentalCaseSheetService $sheets)
    {
        $this->authorize('view', $visit);
        abort_unless(DentalCaseSheetService::canRead(request()->user()), 403);
        $patient = $visit->patient()->withTrashed()->firstOrFail();

        return Pdf::loadView('pdf.dental-instructions', [
            'visit' => $visit->loadMissing('doctor'),
            'patient' => $patient,
            'sheet' => $sheets->sheet($visit, null),
            'hospital' => app(\App\Support\HospitalSettings::class)->hospital(),
        ])->stream("instructions-{$visit->visit_no}.pdf");
    }
}
