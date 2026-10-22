<?php

namespace App\Services;

use App\Models\Visit;
use App\Support\HospitalSettings;
use Illuminate\Support\Collection;

/**
 * The whole of a visit, on paper — see docs/documents.md.
 *
 * This is the document a patient is handed when they are sent somewhere else.
 * Whoever reads it next has none of this system: no login, no history, no way
 * to ask a follow-up question. So it has to answer, on its own, what the next
 * clinician will actually ask —
 *
 *   Who is this, and what must I not give them?   identity, allergies, chronic
 *                                                 conditions, blood group
 *   Why did they come, and what was found?        reason, complaints, vitals,
 *                                                 examination, diagnosis
 *   What was actually done?                       every order, with its items
 *                                                 and the report written on it
 *   What were the results?                        lab figures against their
 *                                                 ranges, radiology findings
 *                                                 and impression
 *   What are they taking right now?               prescriptions and what was
 *                                                 dispensed, with doses
 *   Were they admitted, and for how long?         the stay and its notes
 *   What does the account say?                    charges, invoice, payments
 *
 * Gathering it is this class's job and printing it is the template's, so the
 * shape of the document is readable in one place and every query is eager —
 * a report that N+1s over a long admission is a report nobody waits for.
 */
class VisitReportService
{
    public function __construct(private readonly BillingService $billing) {}

    /**
     * @return array{
     *     visit:Visit,
     *     patient:\App\Models\Patient|null,
     *     age:?string,
     *     vitals:array<int,array{label:string,value:string}>,
     *     hasVitals:bool,
     *     orders:Collection,
     *     labs:Collection,
     *     imaging:Collection,
     *     prescriptions:Collection,
     *     dispensations:Collection,
     *     admissions:Collection,
     *     invoice:\App\Models\Invoice|null,
     *     payments:Collection,
     *     totals:array<string,string>,
     *     attachments:int,
     *     diagnoses:Collection,
     *     dental:array<string,mixed>|null,
     *     dentalNotes:array<string,mixed>|null,
     *     chart:array<int,array<string,mixed>>|null
     * }
     */
    public function assemble(Visit $visit, ?\App\Models\User $reader = null): array
    {
        $visit->loadMissing([
            'patient.district',
            'doctor',
            'department',
            // Every order with what it charged, what it says, and what is
            // pinned to it. `subject` reaches the lab order or admission the
            // work actually happened in.
            'orders' => fn ($q) => $q->with(['items', 'attachments', 'requester', 'assignee', 'subject'])->oldest('id'),
            'labOrders' => fn ($q) => $q->with(['items', 'orderedBy'])->oldest('id'),
            'radiologyOrders' => fn ($q) => $q->with(['items', 'orderedBy', 'reportedBy'])->oldest('id'),
            'prescriptions' => fn ($q) => $q->with(['doseItems', 'prescriber'])->oldest('id'),
            'dispensations' => fn ($q) => $q->with(['items', 'dispensedBy'])->oldest('id'),
            'admissions' => fn ($q) => $q->with(['bed.ward', 'admittingDoctor'])->oldest('id'),
            'invoices' => fn ($q) => $q->with(['payments.receivedBy'])->latest('id'),
        ]);

        $invoice = $visit->invoices->firstWhere(
            fn ($i) => $i->status !== \App\Enums\InvoiceStatus::Void
        );

        return [
            'visit' => $visit,
            'patient' => $visit->patient,
            'age' => $this->age($visit),
            'vitals' => $this->vitals($visit),
            'hasVitals' => $this->vitals($visit) !== [],
            'orders' => $visit->orders,
            'labs' => $visit->labOrders,
            'imaging' => $visit->radiologyOrders,
            'prescriptions' => $visit->prescriptions,
            'dispensations' => $visit->dispensations,
            'admissions' => $visit->admissions,
            'invoice' => $invoice,
            'payments' => $invoice === null ? collect() : $invoice->payments,
            'totals' => $this->totals($visit, $invoice),
            'attachments' => $visit->orders->sum(fn ($order) => $order->attachments->count()),
            'diagnoses' => app(DiagnosisService::class)->current($visit),
            // A dental visit prints as a dental case sheet; any other visit
            // that charted teeth prints the chart (docs/dental.md).
            'dental' => $this->dental($visit, $reader),
            // A dental visit written as simple notes prints them instead
            // (docs/dental.md, "Simple notes").
            'dentalNotes' => $this->dentalNotes($visit, $reader),
            'chart' => $this->chart($visit),
        ];
    }

    /**
     * The patient summary (Settings, Your practice, "Visit report shows"; a
     * dental clinic's default): what the patient takes home, and nothing of
     * the clinic's own record. The treatment done, the dosage, the bill with
     * its payments and balance, and the appointments still to come. No
     * history, examination, findings, diagnosis, notes or chart.
     *
     * @return array{
     *     visit:Visit,
     *     patient:\App\Models\Patient|null,
     *     age:?string,
     *     treatment:list<array{name:string,tooth:string|null}>,
     *     dosage:Collection<int,\App\Models\DoseItem>,
     *     lines:list<array{name:string,quantity:string,amount:string}>,
     *     invoice:\App\Models\Invoice|null,
     *     payments:Collection<int,\App\Models\Payment>,
     *     totals:array<string,string>,
     *     appointments:Collection<int,\App\Models\Appointment>
     * }
     */
    public function summary(Visit $visit): array
    {
        $visit->loadMissing([
            'patient',
            'doctor',
            'prescriptions' => fn ($q) => $q->with('doseItems')->oldest('id'),
            'invoices' => fn ($q) => $q->with(['items', 'payments'])->latest('id'),
            'orderItems',
        ]);

        $invoice = $visit->invoices->firstWhere(fn ($i) => $i->status !== \App\Enums\InvoiceStatus::Void);
        $billable = $visit->orderItems->filter(fn ($line) => $line->status->isBillable())->sortBy('id')->values();

        // The bill: the invoice's own lines once raised, else the bill as it stands.
        $lines = $invoice !== null
            ? $invoice->items->map(fn ($i) => ['name' => (string) $i->description, 'quantity' => (string) $i->quantity, 'amount' => (string) $i->line_total])
            : $billable->map(fn ($i) => ['name' => (string) $i->name, 'quantity' => (string) $i->quantity, 'amount' => (string) $i->line_total]);

        // Treatment done: the dental procedures done on this visit with their
        // teeth, else the services charged on it.
        $treatment = [];
        if ($visit->patient !== null && DentalService::enabledFor(app(HospitalSettings::class)->hospital())) {
            foreach (app(DentalCaseSheetService::class)->doneToday($visit) as $done) {
                $treatment[] = [
                    'name' => (string) $done['procedure'],
                    'tooth' => $done['tooth'] === null ? null : trim($done['tooth'].' '.($done['surfaces'] ?? '')),
                ];
            }
        }
        if ($treatment === []) {
            foreach ($billable as $line) {
                if ($line->service_id !== null) {
                    $treatment[] = ['name' => (string) $line->name, 'tooth' => null];
                }
            }
        }

        $appointments = $visit->patient === null ? collect() : \App\Models\Appointment::query()
            ->with('doctor:id,name')
            ->where('patient_id', $visit->patient_id)
            ->whereIn('status', [\App\Enums\AppointmentStatus::Scheduled->value, \App\Enums\AppointmentStatus::Confirmed->value])
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')->limit(5)->get();

        return [
            'visit' => $visit,
            'patient' => $visit->patient,
            'age' => $this->age($visit),
            'treatment' => $treatment,
            'dosage' => $visit->prescriptions->flatMap(fn ($p) => $p->doseItems)->values(),
            'lines' => $lines->values()->all(),
            'invoice' => $invoice,
            'payments' => $invoice === null ? collect() : $invoice->payments->sortBy('created_at')->values(),
            'totals' => $this->totals($visit, $invoice),
            'appointments' => $appointments,
        ];
    }

    /**
     * The dental case sheet, for a dental visit and a reader who may read it
     * (the web hides it from the desk, so the printout does too). Anyone else
     * gets the general layout: reason, complaints and diagnosis.
     *
     * @return array<string,mixed>|null
     */
    private function dental(Visit $visit, ?\App\Models\User $reader): ?array
    {
        $hospital = app(HospitalSettings::class)->hospital();
        if ($visit->patient === null || ! DentalCaseSheetService::appliesTo($visit, $hospital)
            || DentalNotesService::appliesTo($visit, $hospital)
            || ($reader !== null && ! DentalCaseSheetService::canRead($reader))) {
            return null;
        }

        return app(DentalCaseSheetService::class)->sheet($visit, null);
    }

    /**
     * A dental visit's simple notes, in print: the presenting complaint,
     * History and Oral examination (only the headings written), the chart as
     * it stood, and the treatment done today. Same reader rule as the case sheet.
     *
     * @return array<string,mixed>|null
     */
    private function dentalNotes(Visit $visit, ?\App\Models\User $reader): ?array
    {
        $hospital = app(HospitalSettings::class)->hospital();
        if ($visit->patient === null || ! DentalNotesService::appliesTo($visit, $hospital)
            || ($reader !== null && ! DentalCaseSheetService::canRead($reader))) {
            return null;
        }

        $sheets = app(DentalCaseSheetService::class);

        return app(DentalNotesService::class)->printable($visit) + [
            // A closed visit reprints the mouth as it was when it closed.
            'chart' => app(DentalService::class)->chart($visit->patient, $visit->isOpen() ? null : $visit->completed_at),
            'done_today' => $sheets->doneToday($visit),
        ];
    }

    /** @return array<int, array<string,mixed>>|null the chart, when this visit charted teeth outside a case sheet */
    private function chart(Visit $visit): ?array
    {
        $hospital = app(HospitalSettings::class)->hospital();
        if ($visit->patient === null || ! DentalService::enabledFor($hospital)
            || ! \App\Models\DentalChartEntry::query()->where('visit_id', $visit->id)->exists()) {
            return null;
        }

        return app(DentalService::class)->chart($visit->patient);
    }

    /**
     * The vitals, only the ones that were actually taken.
     *
     * A row of dashes tells a reader nothing and costs them the time to work
     * out that it tells them nothing, so a reading nobody recorded is simply
     * not on the page.
     *
     * @return array<int,array{label:string,value:string}>
     */
    public function vitals(Visit $visit): array
    {
        $readings = [
            ['Temperature', $visit->temperature, '°C'],
            ['Pulse', $visit->pulse, 'bpm'],
            ['Blood pressure', $visit->blood_pressure, 'mmHg'],
            ['Respiratory rate', $visit->respiratory_rate, '/min'],
            ['SpO₂', $visit->spo2, '%'],
            ['Weight', $visit->weight, 'kg'],
            ['Height', $visit->height, 'cm'],
            ['BMI', $visit->bmi, ''],
        ];

        $rows = [];

        foreach ($readings as [$label, $value, $unit]) {
            if ($value === null || trim((string) $value) === '') {
                continue;
            }

            $shown = $this->tidy((string) $value);
            $rows[] = ['label' => $label, 'value' => trim($shown.' '.$unit)];
        }

        return $rows;
    }

    /**
     * What the account says.
     *
     * An invoiced visit shows the invoice's own frozen figures; an uninvoiced
     * one shows the bill as it stands. Both go through BillingService, so this
     * document can never quote a total the bill panel would not.
     *
     * @return array<string,string>
     */
    private function totals(Visit $visit, ?\App\Models\Invoice $invoice): array
    {
        if ($invoice !== null) {
            return [
                'subtotal' => (string) $invoice->subtotal,
                'discount' => (string) $invoice->discount,
                'tax' => (string) $invoice->tax_total,
                'total' => (string) $invoice->total,
                'paid' => (string) $invoice->amount_paid,
                'balance' => (string) $invoice->balance,
            ];
        }

        $figures = $this->billing->totalsFor($visit);

        return [
            'subtotal' => $figures['subtotal'],
            'discount' => $figures['discount'],
            'tax' => $figures['tax'],
            'total' => $figures['due'],
            'paid' => '0.00',
            'balance' => $figures['due'],
        ];
    }

    /** Age at the time of the visit, not age today — a report is a record. */
    private function age(Visit $visit): ?string
    {
        $dob = $visit->patient?->dob;

        if ($dob === null) {
            return null;
        }

        $at = $visit->created_at ?? now();
        $years = $dob->diffInYears($at);

        if ($years >= 1) {
            return ((int) $years).' yrs';
        }

        $months = (int) $dob->diffInMonths($at);

        return $months >= 1 ? $months.' mo' : ((int) $dob->diffInDays($at)).' days';
    }

    /** 37.50 reads as 37.5; 2.00 reads as 2. A trailing zero is noise. */
    private function tidy(string $value): string
    {
        if (! is_numeric($value)) {
            return $value;
        }

        return rtrim(rtrim(HospitalSettings::decimal($value, 2), '0'), '.');
    }
}
