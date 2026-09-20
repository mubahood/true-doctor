<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Hospital;
use App\Models\Patient;
use App\Services\QrService;
use App\Support\CurrentHospital;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * What is left of the classic patient controller after Phase 3: the ID-card
 * PDF. Listing, registration, editing, the record workspace and the archive
 * action are Livewire (App\Livewire\Patients\{Index,Form,Show}); every patient
 * sub-resource is written by a Patients\Panels\* child component.
 */
class PatientController extends Controller
{
    /**
     * A printable A7 patient ID card (PDF) with a QR of the patient number.
     * Rendered server-side via DomPDF; the QR is an inline data-URI so the PDF
     * is fully self-contained (no external fetches).
     */
    public function idCard(Patient $patient, QrService $qr)
    {
        $this->authorize('view', $patient);

        $hospitalId = app(CurrentHospital::class)->id();
        $hospital = $hospitalId ? Hospital::find($hospitalId) : null;

        $pdf = Pdf::loadView('pdf.patient-card', [
            'patient' => $patient,
            'hospital' => $hospital,
            'qr' => $qr->pngDataUri($patient->patient_no, 260),
        ])->setPaper([0, 0, 297.64, 209.76], 'landscape'); // A7 landscape (74×105mm)

        return $pdf->stream("patient-card-{$patient->patient_no}.pdf");
    }
}
