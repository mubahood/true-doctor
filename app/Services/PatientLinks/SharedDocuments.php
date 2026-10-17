<?php

namespace App\Services\PatientLinks;

use App\Enums\AdmissionStatus;
use App\Enums\LabOrderStatus;
use App\Enums\RadiologyOrderStatus;
use App\Enums\SharedDocumentType;
use App\Models\Admission;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\RadiologyOrder;
use App\Models\Visit;
use App\Services\DentalService;
use App\Support\HospitalSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;

/**
 * The documents a patient link can open, each the same PDF staff print, so a
 * link and a printout never say different things.
 *
 * Every lookup goes through the tenant scope: a record of another hospital is
 * not found.
 */
final class SharedDocuments
{
    public function __construct(private readonly DentalService $dental) {}

    /** The record by its uuid (a patient's, for a quote), or null. */
    public function find(SharedDocumentType $type, string $uuid): ?Model
    {
        return $this->query($type)->where('uuid', $uuid)->first();
    }

    public function findById(SharedDocumentType $type, int $id): ?Model
    {
        return $this->query($type)->whereKey($id)->first();
    }

    public function patientOf(SharedDocumentType $type, Model $record): ?Patient
    {
        if ($record instanceof Patient) {
            return $record;
        }

        /** @var Patient|null $patient */
        $patient = $record->getRelationValue('patient');

        return $patient;
    }

    /** The number printed on the document, so staff and patient speak of the same one. */
    public function reference(SharedDocumentType $type, Model $record): ?string
    {
        return match ($type) {
            SharedDocumentType::LabResult => 'LAB-'.str_pad((string) $record->getKey(), 6, '0', STR_PAD_LEFT),
            SharedDocumentType::RadiologyReport => 'RAD-'.str_pad((string) $record->getKey(), 6, '0', STR_PAD_LEFT),
            SharedDocumentType::DischargeSummary => 'ADM-'.str_pad((string) $record->getKey(), 6, '0', STR_PAD_LEFT),
            SharedDocumentType::Prescription => $record instanceof Visit ? $record->visit_no : null,
            SharedDocumentType::DentalQuote => $record instanceof Patient ? $record->patient_no : null,
        };
    }

    /**
     * Why this document cannot be sent yet, or null when it can. A result is
     * sent once the lab has released it, a summary once the patient has gone
     * home, and an empty prescription or quote is not sent at all.
     */
    public function notReady(SharedDocumentType $type, Model $record): ?string
    {
        return match ($type) {
            SharedDocumentType::LabResult => $record instanceof LabOrder && $record->status === LabOrderStatus::Completed
                ? null : 'The lab has not released this result yet.',
            SharedDocumentType::RadiologyReport => $record instanceof RadiologyOrder && $record->status === RadiologyOrderStatus::Reported
                ? null : 'This study has not been reported yet.',
            SharedDocumentType::DischargeSummary => $record instanceof Admission && $record->status === AdmissionStatus::Discharged
                ? null : 'A discharge summary is sent once the patient is discharged.',
            SharedDocumentType::Prescription => $record instanceof Visit && $record->prescriptions()->whereHas('doseItems')->exists()
                ? null : 'Nothing has been prescribed on this visit.',
            SharedDocumentType::DentalQuote => $record instanceof Patient && $this->dental->quote($record)['items']->isNotEmpty()
                ? null : 'There is no planned treatment to quote.',
        };
    }

    public function filename(SharedDocumentType $type, Model $record): string
    {
        return str_replace('_', '-', $type->value).'-'.($this->reference($type, $record) ?? $record->getKey()).'.pdf';
    }

    /** The PDF, drawn by the same template as the staff copy. */
    public function pdf(SharedDocumentType $type, Model $record): \Barryvdh\DomPDF\PDF
    {
        $hospital = app(HospitalSettings::class)->hospital();

        return match ($type) {
            SharedDocumentType::LabResult => Pdf::loadView('pdf.lab-result', [
                'order' => $record->load(['items', 'patient', 'orderedBy']), 'hospital' => $hospital,
            ]),
            SharedDocumentType::RadiologyReport => Pdf::loadView('pdf.radiology-report', [
                'order' => $record->load(['items', 'patient', 'orderedBy']), 'hospital' => $hospital,
            ]),
            SharedDocumentType::DischargeSummary => Pdf::loadView('pdf.discharge-summary', [
                'admission' => $record->load(['patient', 'bed.ward', 'admittingDoctor', 'transfers.fromBed', 'transfers.toBed']),
                'hospital' => $hospital,
            ]),
            SharedDocumentType::Prescription => Pdf::loadView('pdf.prescription', [
                'visit' => $record->load(['patient', 'doctor', 'prescriptions.doseItems', 'prescriptions.prescriber']),
                'hospital' => $hospital,
            ]),
            SharedDocumentType::DentalQuote => Pdf::loadView('pdf.dental-quote', [
                'patient' => $record,
                'quote' => $record instanceof Patient ? $this->dental->quote($record) : ['items' => collect(), 'total' => '0.00'],
                'hospital' => $hospital,
            ]),
        };
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Admission>|\Illuminate\Database\Eloquent\Builder<LabOrder>|\Illuminate\Database\Eloquent\Builder<Patient>|\Illuminate\Database\Eloquent\Builder<RadiologyOrder>|\Illuminate\Database\Eloquent\Builder<Visit> */
    private function query(SharedDocumentType $type): \Illuminate\Database\Eloquent\Builder
    {
        return match ($type) {
            SharedDocumentType::LabResult => LabOrder::query()->with('patient'),
            SharedDocumentType::RadiologyReport => RadiologyOrder::query()->with('patient'),
            SharedDocumentType::DischargeSummary => Admission::query()->with('patient'),
            SharedDocumentType::Prescription => Visit::query()->with('patient'),
            SharedDocumentType::DentalQuote => Patient::query(),
        };
    }
}
