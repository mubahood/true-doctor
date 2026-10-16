<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Services\DentalService;
use Barryvdh\DomPDF\Facade\Pdf;

/** The patient's treatment quote as a PDF: planned and accepted procedures with the total. */
class DentalQuoteController extends Controller
{
    public function __invoke(Patient $patient, DentalService $dental)
    {
        $this->authorize('view', $patient);
        abort_unless(auth()->user()->can('treatments.view') || auth()->user()->can('visits.diagnose'), 403);

        return Pdf::loadView('pdf.dental-quote', [
            'patient' => $patient,
            'quote' => $dental->quote($patient),
            'hospital' => app(\App\Support\HospitalSettings::class)->hospital(),
        ])->stream("quote-{$patient->patient_no}.pdf");
    }
}
