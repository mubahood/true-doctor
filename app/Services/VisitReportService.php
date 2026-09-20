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
     *     attachments:int
     * }
     */
    public function assemble(Visit $visit): array
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
        ];
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
