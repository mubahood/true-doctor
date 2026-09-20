<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * What is left of the inpatient controller after Phase 3: the discharge-summary
 * PDF, which is a genuine download and so legitimately leaves the SPA. The
 * occupancy board, the admission workspace, bed transfer, discharge and every
 * nursing-round entry are Livewire components now
 * (App\Livewire\Admissions\Board / Show / Panels\*).
 */
class AdmissionController extends Controller
{
    public function summaryPdf(Admission $admission)
    {
        $this->authorize('view', $admission);

        $admission->load(['patient', 'bed.ward', 'admittingDoctor', 'transfers.fromBed', 'transfers.toBed']);
        $hospital = app(\App\Support\HospitalSettings::class)->hospital();

        return Pdf::loadView('pdf.discharge-summary', ['admission' => $admission, 'hospital' => $hospital])
            ->stream("discharge-summary-{$admission->uuid}.pdf");
    }
}
