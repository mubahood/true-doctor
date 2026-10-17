<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Visit;
use App\Services\VisitReportService;
use App\Support\HospitalSettings;
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
        // the receipt and the invoice instead (docs/rbac.md).
        $this->authorize('viewClinical', $visit);
        $patient = $visit->patient()->withTrashed()->first();
        if ($patient !== null) {
            app(\App\Services\Audit\RecordAccessLog::class)->printed($patient, auth()->user(), 'Visit report '.$visit->visit_no);
        }

        $report = $reports->assemble($visit);

        return Pdf::loadView('pdf.visit-report', [
            'report' => $report,
            'hospital' => app(HospitalSettings::class)->hospital(),
        ])->stream("visit-report-{$visit->visit_no}.pdf");
    }
}
