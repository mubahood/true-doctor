<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Services\DentalService;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * The patient's treatment quote as a PDF: planned and accepted procedures with
 * the total. The panel's link and the app's Quote button (`api.dental.quote`).
 */
class DentalQuoteController extends Controller
{
    public function __invoke(Patient $patient, DentalService $dental)
    {
        $this->authorize('view', $patient);
        $user = request()->user();
        abort_unless($user !== null && ($user->can('treatments.view') || $user->can('visits.diagnose')), 403);

        return Pdf::loadView('pdf.dental-quote', [
            'patient' => $patient,
            'quote' => $dental->quote($patient),
            'hospital' => app(\App\Support\HospitalSettings::class)->hospital(),
        ])->stream("quote-{$patient->patient_no}.pdf");
    }
}
