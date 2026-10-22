<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Visit;
use App\Services\VisitReportService;
use App\Support\HospitalSettings;
use App\Support\Practice\VisitForm;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Visits are worked entirely in Livewire (App\Livewire\Visits\*). The one thing
 * that has to leave the SPA is this: the full visit report, as a PDF a patient
 * can carry to another hospital.
 */
class VisitController extends Controller
{
    public function report(Visit $visit, VisitReportService $reports)
    {
        // `view`, not `manage`: this is the record of the visit, and everyone
        // who may read the visit may print what it says. Assembling it touches
        // the bill, so a role without billing sees the account section as
        // figures it already has access to through the visit itself.
        $this->authorize('view', $visit);
        // The report IS the clinical record: the desk and the cashier print
        // the receipt and the invoice instead (docs/rbac.md), unless they were
        // given the report on its own (`visits.report`, a dental clinic's desk).
        $this->authorize('printReport', $visit);
        $reader = auth()->user();
        $patient = $visit->patient()->withTrashed()->first();
        if ($patient !== null) {
            app(\App\Services\Audit\RecordAccessLog::class)->printed($patient, $reader, 'Visit report '.$visit->visit_no);
        }

        // A practice that hands the patient a summary (Settings, Your practice,
        // "Visit report shows"; a dental clinic's default) prints what the
        // patient takes home: no history, internal notes or vitals leave it.
        // Somebody who prints without reading the record gets the summary too.
        if (VisitForm::current()->printsSummary() || ! $reader?->can('viewClinical', $visit)) {
            return Pdf::loadView('pdf.visit-summary', [
                'summary' => $reports->summary($visit, $reader),
                'hospital' => app(HospitalSettings::class)->hospital(),
            ])->stream("visit-summary-{$visit->visit_no}.pdf");
        }

        $report = $reports->assemble($visit, $reader);

        return Pdf::loadView('pdf.visit-report', [
            'report' => $report,
            'hospital' => app(HospitalSettings::class)->hospital(),
        ])->stream("visit-report-{$visit->visit_no}.pdf");
    }
}
