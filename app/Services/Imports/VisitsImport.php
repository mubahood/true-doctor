<?php

namespace App\Services\Imports;

use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\OrderService;
use App\Services\VisitService;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Visits from the clinic's old records, for patients who are registered
 * (import the patients first). Each is recorded through VisitService on its
 * own day and closed; its treatment goes on as a finished order; its bill is
 * an opening entry (BillingService::billPastVisit): what was paid then was
 * paid in the old books and is never today's takings, and only what is still
 * owed comes into these books, like a balance brought forward.
 */
class VisitsImport extends ImportHandler
{
    use Concerns\FindsPatientsAndStaff;

    public function __construct(
        private readonly VisitService $visits,
        private readonly OrderService $orders,
        private readonly BillingService $billing,
    ) {}

    public function fields(): array
    {
        return [
            new ImportField('date', 'Date', 'The day of the visit, day first: 25/03/2025.', today()->subMonths(3)->format('d/m/Y'), true,
                ['visit date', 'date of visit', 'day', 'attended', 'date seen', 'seen on', 'visit']),
            new ImportField('patient_no', 'Patient number', 'Their patient number here, or the old file number you imported them with.', 'PT 1043', false,
                ['patient no', 'patient number', 'file no', 'file number', 'old file no', 'old file number', 'patient id', 'opd no', 'card no', 'reg no', 'id']),
            new ImportField('patient_name', 'Patient name', 'Used with the phone to find them when there is no number.', 'Amina Nakato', false,
                ['patient', 'name', 'names', 'client', 'client name', 'full name', 'patients name']),
            new ImportField('phone', 'Phone', 'Used to find them when there is no number.', '0772 123456', false,
                ['tel', 'telephone', 'contact', 'mobile', 'phone no', 'phone number']),
            new ImportField('doctor', 'Dentist', 'Who saw them, as the name is on their account here. Leave empty if not known.', '', false,
                ['doctor', 'clinician', 'seen by', 'provider', 'staff', 'consultant', 'doctor name', 'dr', 'dentist name']),
            new ImportField('complaint', 'Presenting complaint', 'What they came with.', 'Toothache, lower left', false,
                ['complaint', 'complaints', 'presenting complaint', 'chief complaint', 'reason', 'symptoms', 'c/o', 'history']),
            new ImportField('diagnosis', 'Diagnosis', 'What was found.', 'Dental caries 36', false,
                ['dx', 'findings', 'finding', 'diagnoses', 'impression', 'assessment']),
            new ImportField('treatment', 'Treatment done', 'Names from your price list, separated by commas, or the treatment in your own words.', 'Filling', false,
                ['treatment', 'treatments', 'procedure', 'procedures', 'service', 'services', 'done', 'treatment given', 'management', 'plan']),
            new ImportField('billed', 'Amount billed', 'What the visit came to, as a number. Leave empty if nothing was billed.', '50000', false,
                ['amount', 'bill', 'billed', 'charged', 'charge', 'cost', 'fee', 'fees', 'total', 'amount charged', 'invoice amount']),
            new ImportField('paid', 'Amount paid', 'What they paid for it. Empty means nothing; the rest stays owed.', '50000', false,
                ['paid', 'payment', 'amount paid', 'received', 'cash paid', 'deposit']),
        ];
    }

    public function decisions(): array
    {
        return ['skip', 'add'];
    }

    public function requiredColumns(): array
    {
        return [['date'], ['patient_no', 'patient_name', 'phone']];
    }

    public function check(array $v): array
    {
        $problems = [];
        $date = $this->visitDate($v, $problems);
        $patient = $this->patient($v, $problems);
        if (trim($v['doctor'] ?? '') !== '') {
            $this->doctor($v['doctor'], $problems);
        }

        $billed = $this->money($v['billed'] ?? '');
        $paid = $this->money($v['paid'] ?? '');
        if ($billed === false) {
            $problems[] = 'Amount billed "'.$v['billed'].'" is not an amount.';
        } elseif (is_string($billed) && bccomp($billed, '0', 2) < 0) {
            $problems[] = 'Amount billed is below zero.';
        }
        if ($paid === false) {
            $problems[] = 'Amount paid "'.$v['paid'].'" is not an amount.';
        } elseif (is_string($paid) && bccomp($paid, '0', 2) < 0) {
            $problems[] = 'Amount paid is below zero.';
        } elseif (is_string($paid) && bccomp($paid, '0', 2) > 0 && bccomp($paid, is_string($billed) ? $billed : '0', 2) > 0) {
            $problems[] = 'Amount paid is more than the amount billed.';
        }

        foreach (['complaint' => 'presenting complaint', 'diagnosis' => 'diagnosis', 'treatment' => 'treatment'] as $key => $label) {
            if (mb_strlen($v[$key] ?? '') > 2000) {
                $problems[] = 'The '.$label.' is longer than 2000 characters.';
            }
        }

        $match = null;
        if ($problems === [] && $patient !== null && $date !== null) {
            $hit = Visit::query()->where('patient_id', $patient->id)->whereDate('created_at', $date->toDateString())->first();
            if ($hit !== null) {
                $match = ['id' => $hit->id, 'reason' => 'Already has a visit that day', 'label' => $patient->full_name.', '.$date->format('j M Y').' ('.$hit->visit_no.')'];
            }
        }

        return ['problems' => $problems, 'match' => $match];
    }

    public function sheetKey(array $v): ?string
    {
        $date = $this->date($v['date'] ?? '');
        $who = mb_strtolower(trim(($v['patient_no'] ?? '') !== '' ? $v['patient_no'] : ($v['patient_name'] ?? '').'|'.($v['phone'] ?? '')));

        return is_string($date) && trim($who, '|') !== '' ? $date.'|'.$who.'|'.mb_strtolower(trim($v['treatment'] ?? '')) : null;
    }

    public function save(array $v, string $decision, ?int $matchId, ImportBatch $batch, ?int $by): array
    {
        $problems = [];
        $date = $this->visitDate($v, $problems);
        $patient = $this->patient($v, $problems);
        $doctor = trim($v['doctor'] ?? '') !== '' ? $this->doctor($v['doctor'], $problems) : null;
        if ($problems !== [] || $patient === null || $date === null) {
            throw new RuntimeException($problems[0] ?? 'This row could not be read.');
        }

        $complaint = $this->text($v['complaint'] ?? '');
        $visit = $this->visits->recordPast([
            'patient_id' => $patient->id,
            'doctor_user_id' => $doctor?->id,
            'complaints' => $complaint,
            'diagnosis' => $this->text($v['diagnosis'] ?? ''),
            'reason' => $complaint !== null ? null : 'Visit from the old records',
        ], $date, $by);

        $billed = $this->money($v['billed'] ?? '');
        $billed = is_string($billed) ? $billed : '0.00';
        $paid = $this->money($v['paid'] ?? '');
        $paid = is_string($paid) ? $paid : '0.00';
        $treatment = $this->text($v['treatment'] ?? '');

        if ($treatment !== null || bccomp($billed, '0', 2) > 0) {
            $this->treatment($visit, $treatment, $billed, $by);
        }

        $extra = [];
        if (bccomp($billed, '0', 2) > 0) {
            $extra['invoice_id'] = $this->billing->billPastVisit($visit, $billed, $paid, $by)->id;
        }

        return ['status' => ImportRow::ADDED, 'type' => Visit::class, 'id' => $visit->id, 'extra' => $extra, 'message' => $visit->visit_no];
    }

    public function undoBlocker(ImportRow $row): ?string
    {
        $visit = Visit::query()->find($row->record_id);
        if ($visit === null) {
            return null; // deleted already: nothing left to take back
        }

        $invoiceId = (int) ($row->extra['invoice_id'] ?? 0);
        $invoice = $invoiceId > 0 ? Invoice::query()->find($invoiceId) : null;

        return match (true) {
            $invoice !== null && $invoice->status !== InvoiceStatus::Void
                && (bccomp((string) $invoice->amount_paid, (string) $invoice->paid_before, 2) !== 0 || bccomp((string) $invoice->credited, '0', 2) !== 0) => 'Has been paid on since.',
            Invoice::query()->where('visit_id', $visit->id)->whereKeyNot($invoiceId)->exists() => 'Has been billed since.',
            Order::query()->where('visit_id', $visit->id)->where('created_at', '>', $row->updated_at ?? now())->exists() => 'Has had work added since.',
            default => null,
        };
    }

    public function undo(ImportRow $row, ?int $by): void
    {
        $visit = Visit::query()->find($row->record_id);
        if ($visit === null) {
            return;
        }

        // The same path as "Delete visit": the order off, the bill voided
        // and its entry reversed, the visit soft-deleted with the reason.
        $this->visits->delete($visit, $by, 'Import undone');
    }

    // ── Reading a row ──────────────────────────────────────────────────

    /** @param list<string> $problems */
    private function visitDate(array $v, array &$problems): ?Carbon
    {
        $date = $this->date($v['date'] ?? '');
        if ($date === null) {
            $problems[] = 'There is no date.';

            return null;
        }
        if ($date === false) {
            $problems[] = 'Date "'.$v['date'].'" is not a date we can read. Write it like 25/03/2025.';

            return null;
        }
        if ($date > today()->toDateString()) {
            $problems[] = 'The date '.Carbon::parse($date)->format('j M Y').' is in the future. Only visits that happened are imported.';

            return null;
        }

        // Mid-morning, so the visit sorts inside its day; a visit from earlier
        // today is dated now rather than later this morning.
        $at = Carbon::parse($date.' 09:00:00');

        return $at->isFuture() ? Carbon::now() : $at;
    }

    private function text(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 2000);
    }

    /**
     * The treatment as a finished order on the visit. Names on the price
     * list become their own lines when their prices add up to the amount
     * billed; otherwise one line carries the whole amount, under the name of
     * the one price-list entry it names, or the words as written.
     */
    private function treatment(Visit $visit, ?string $treatment, string $billed, ?int $by): void
    {
        $title = $treatment !== null ? mb_substr($treatment, 0, 191) : 'Treatment';
        $order = $this->orders->place($visit, OrderType::Procedure, $title, ['status' => OrderStatus::Completed], $by);
        $order->forceFill(['completed_at' => $visit->completed_at])->save();

        $names = $treatment !== null ? $this->list($treatment) : [];
        $services = [];
        foreach ($names as $name) {
            $service = Service::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->orderByDesc('is_active')->first();
            if ($service === null) {
                $services = [];
                break;
            }
            $services[] = $service;
        }

        $sum = array_reduce($services, fn (string $c, Service $s) => bcadd($c, (string) $s->price, 2), '0.00');
        if ($services !== [] && bccomp($sum, $billed, 2) === 0 && bccomp($billed, '0', 2) > 0) {
            foreach ($services as $service) {
                app(BillingService::class)->addItem($order, [
                    'service_id' => $service->id, 'name' => $service->name, 'unit_price' => (string) $service->price, 'quantity' => 1, 'tax_exempt' => true,
                ], $by);
            }

            return;
        }

        if (bccomp($billed, '0', 2) <= 0) {
            return; // the treatment is recorded on the order; nothing was billed for it
        }

        $one = count($services) === 1 ? $services[0] : null;
        app(BillingService::class)->addItem($order, [
            'service_id' => $one?->id,
            'name' => $one !== null ? $one->name : $title,
            'unit_price' => $billed,
            'quantity' => 1,
            'tax_exempt' => true,
        ], $by);
    }
}
