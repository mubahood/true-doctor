<?php

namespace App\Services\Reports;

use App\Enums\InvoiceStatus;
use App\Enums\OrderItemStatus;
use App\Enums\VisitOutcome;
use App\Models\DentalTreatmentItem;
use App\Models\Invoice;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitDiagnosis;
use Illuminate\Database\Eloquent\Builder;

/**
 * Patient records: the patient register a clinic kept on paper, one line per
 * visit in the period.
 *
 * A visit is "new" when it is the patient's first visit here, the rule the
 * Ministry's 105 counts by (HmisReports). The diagnosis is the coded one in
 * force (DiagnosisService::current), else what was written. The treatment is
 * what was charged on the visit, with the tooth where a dental procedure was
 * on one. Billed, paid and balance are the visit's invoice: what it now asks
 * for after credit notes, what is held after refunds, and what is left; a
 * visit not yet invoiced shows what its lines come to, with nothing paid.
 * Cancelled visits are left out: the patient was not seen.
 */
class PatientRecordsReport extends MonthlyReport
{
    /** Age bands, as the reports page counts the register. */
    public const AGE_BANDS = ['0-17', '18-39', '40-64', '65+', 'Unknown'];

    public function title(): string
    {
        return 'Patient records report';
    }

    public function columns(): array
    {
        return [
            ['key' => 'date', 'label' => 'Visit date', 'type' => 'date'],
            ['key' => 'patient_no', 'label' => 'Patient no', 'type' => 'text'],
            ['key' => 'patient', 'label' => 'Name', 'type' => 'text'],
            ['key' => 'sex', 'label' => 'Sex', 'type' => 'text'],
            ['key' => 'age', 'label' => 'Age', 'type' => 'int'],
            ['key' => 'phone', 'label' => 'Phone', 'type' => 'text'],
            ['key' => 'attendance', 'label' => 'New or returning', 'type' => 'text'],
            ['key' => 'complaint', 'label' => 'Presenting complaint', 'type' => 'text'],
            ['key' => 'diagnosis', 'label' => 'Diagnosis', 'type' => 'text'],
            ['key' => 'treatment', 'label' => 'Treatment done', 'type' => 'text'],
            ['key' => 'clinician', 'label' => self::clinician(), 'type' => 'text'],
            ['key' => 'billed', 'label' => 'Billed', 'type' => 'money'],
            ['key' => 'paid', 'label' => 'Paid', 'type' => 'money'],
            ['key' => 'balance', 'label' => 'Balance', 'type' => 'money'],
        ];
    }

    /** @return Builder<Visit> */
    private function visits(ReportPeriod $p): Builder
    {
        return Visit::query()
            ->where('visits.created_at', '>=', $p->start())->where('visits.created_at', '<', $p->end())
            ->where(fn ($q) => $q->whereNull('outcome')->orWhere('outcome', '!=', VisitOutcome::Cancelled->value));
    }

    public function count(ReportPeriod $period): int
    {
        return $this->visits($period)->count();
    }

    /**
     * Each visit with what the line needs, a few hundred at a time: one query
     * per chunk for each of first visits, charges, teeth, diagnoses and bills.
     *
     * @return \Generator<int, array{visit:Visit,new:bool,items:list<array{name:string,amount:string}>,diagnoses:list<string>,billed:string,paid:string,balance:string}>
     */
    private function each(ReportPeriod $period, int $offset = 0, ?int $limit = null): \Generator
    {
        $visits = $this->visits($period)
            ->with(['patient:id,patient_no,first_name,last_name,sex,dob,phone_1', 'doctor:id,name'])
            ->orderBy('visits.created_at')->orderBy('visits.id');

        foreach (self::window($visits, $offset, $limit, 300) as $chunk) {
            $ids = $chunk->pluck('id')->all();

            $first = Visit::query()->whereIn('patient_id', $chunk->pluck('patient_id')->unique()->all())
                ->selectRaw('patient_id, MIN(id) as first_id')->groupBy('patient_id')->pluck('first_id', 'patient_id');

            $items = OrderItem::query()
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereIn('orders.visit_id', $ids)->whereNull('orders.deleted_at')
                ->where('order_items.status', '!=', OrderItemStatus::Cancelled->value)
                ->orderBy('order_items.id')
                ->get(['order_items.id', 'order_items.name', 'order_items.line_total', 'orders.visit_id']);
            $teeth = DentalTreatmentItem::query()->whereIn('order_item_id', $items->pluck('id')->all())->whereNotNull('tooth')->pluck('tooth', 'order_item_id');

            $diagnoses = VisitDiagnosis::query()->whereIn('visit_id', $ids)->where('removed', false)
                ->whereNotIn('id', VisitDiagnosis::query()->whereIn('visit_id', $ids)->whereNotNull('replaces_id')->select('replaces_id'))
                ->orderByDesc('is_primary')->orderBy('id')->get(['visit_id', 'title'])->groupBy('visit_id');

            $invoices = Invoice::query()->whereIn('visit_id', $ids)
                ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Void->value])
                ->orderBy('id')->get(['id', 'visit_id', 'total', 'credited', 'amount_paid', 'refunded', 'balance'])->keyBy('visit_id');

            $byVisit = $items->groupBy('visit_id');

            foreach ($chunk as $visit) {
                $lines = [];
                $sum = '0.00';
                foreach ($byVisit->get($visit->id, collect()) as $item) {
                    $tooth = $teeth[$item->id] ?? null;
                    $lines[] = ['name' => (string) $item->name.($tooth ? ' (tooth '.$tooth.')' : ''), 'amount' => (string) $item->line_total];
                    $sum = bcadd($sum, (string) $item->line_total, 2);
                }

                $invoice = $invoices->get($visit->id);
                $billed = $invoice ? $invoice->netTotal() : $sum;
                $paid = $invoice ? $invoice->held() : '0.00';

                yield [
                    'visit' => $visit,
                    'new' => (int) ($first[$visit->patient_id] ?? 0) === (int) $visit->id,
                    'items' => $lines,
                    'diagnoses' => $diagnoses->get($visit->id, collect())->pluck('title')->filter()->unique()->values()->all(),
                    'billed' => $billed,
                    'paid' => $paid,
                    'balance' => $invoice ? (string) $invoice->balance : $sum,
                ];
            }
        }
    }

    private function ageAt(Visit $visit): ?int
    {
        $dob = $visit->patient?->dob;

        return $dob === null ? null : (int) $dob->diffInYears($visit->created_at);
    }

    private static function band(?int $age): string
    {
        return match (true) {
            $age === null => 'Unknown',
            $age < 18 => '0-17',
            $age < 40 => '18-39',
            $age < 65 => '40-64',
            default => '65+',
        };
    }

    public function rows(ReportPeriod $period, int $offset = 0, ?int $limit = null): \Generator
    {
        foreach ($this->each($period, $offset, $limit) as $v) {
            $visit = $v['visit'];
            $patient = $visit->patient;

            yield [
                'date' => $period->localDate($visit->created_at),
                'patient_no' => $patient?->patient_no,
                'patient' => $patient?->full_name,
                'sex' => $patient?->sex?->label(),
                'age' => $this->ageAt($visit),
                'phone' => $patient?->phone_1,
                'attendance' => $v['new'] ? 'New' : 'Returning',
                // Clinical notes only for somebody who may read them (visits.clinical).
                'complaint' => $this->clinical ? trim((string) ($visit->complaints ?: $visit->reason)) : '',
                'diagnosis' => ! $this->clinical ? '' : ($v['diagnoses'] !== [] ? implode('; ', $v['diagnoses']) : trim((string) $visit->diagnosis)),
                'treatment' => implode(', ', array_column($v['items'], 'name')),
                'clinician' => $visit->doctor?->name,
                'billed' => $v['billed'],
                'paid' => $v['paid'],
                'balance' => $v['balance'],
            ];
        }
    }

    public function totals(ReportPeriod $period): array
    {
        $s = $this->tally($period);

        return [
            ['label' => 'Visits', 'value' => $s['visits'], 'type' => 'int'],
            ['label' => 'Patients seen', 'value' => count($s['patients']), 'type' => 'int'],
            ['label' => 'Billed', 'value' => $s['billed'], 'type' => 'money'],
            ['label' => 'Paid', 'value' => $s['paid'], 'type' => 'money'],
            ['label' => 'Balance', 'value' => $s['balance'], 'type' => 'money'],
        ];
    }

    /**
     * Everything the summary counts, in one pass.
     *
     * @return array{visits:int,new:int,patients:array<int,array{sex:string,band:string}>,days:array<string,int>,treatments:array<string,array{times:int,amount:string}>,diagnoses:array<string,int>,clinicians:array<int,array{visits:int,patients:array<int,true>,billed:string}>,billed:string,paid:string,balance:string}
     */
    private function tally(ReportPeriod $period): array
    {
        $t = ['visits' => 0, 'new' => 0, 'patients' => [], 'days' => [], 'treatments' => [], 'diagnoses' => [], 'clinicians' => [], 'billed' => '0.00', 'paid' => '0.00', 'balance' => '0.00'];

        foreach ($this->each($period) as $v) {
            $visit = $v['visit'];
            $t['visits']++;
            $t['new'] += $v['new'] ? 1 : 0;
            $t['billed'] = bcadd($t['billed'], $v['billed'], 2);
            $t['paid'] = bcadd($t['paid'], $v['paid'], 2);
            $t['balance'] = bcadd($t['balance'], $v['balance'], 2);

            // A patient is counted once, as they were at their first visit in the period.
            $t['patients'][(int) $visit->patient_id] ??= [
                'sex' => $visit->patient?->sex?->label() ?? 'Not recorded',
                'band' => self::band($this->ageAt($visit)),
            ];

            $day = $period->localDate($visit->created_at);
            $t['days'][$day] = ($t['days'][$day] ?? 0) + 1;

            foreach ($v['items'] as $item) {
                // The tooth is the line's detail, not a different treatment.
                $name = (string) preg_replace('/ \(tooth \d+\)$/', '', $item['name']);
                $t['treatments'][$name] ??= ['times' => 0, 'amount' => '0.00'];
                $t['treatments'][$name]['times']++;
                $t['treatments'][$name]['amount'] = bcadd($t['treatments'][$name]['amount'], $item['amount'], 2);
            }

            $dx = $v['diagnoses'] !== [] ? $v['diagnoses'] : array_filter([trim((string) $visit->diagnosis)]);
            foreach ($dx as $d) {
                $t['diagnoses'][$d] = ($t['diagnoses'][$d] ?? 0) + 1;
            }

            $who = (int) ($visit->doctor_user_id ?? 0);
            $t['clinicians'][$who] ??= ['visits' => 0, 'patients' => [], 'billed' => '0.00'];
            $t['clinicians'][$who]['visits']++;
            $t['clinicians'][$who]['patients'][(int) $visit->patient_id] = true;
            $t['clinicians'][$who]['billed'] = bcadd($t['clinicians'][$who]['billed'], $v['billed'], 2);
        }

        return $t;
    }

    public function summary(ReportPeriod $period): array
    {
        $t = $this->tally($period);
        $patients = count($t['patients']);
        $clinician = self::clinician();

        $bySex = [];
        $byBand = array_fill_keys(self::AGE_BANDS, 0);
        foreach ($t['patients'] as $p) {
            $bySex[$p['sex']] = ($bySex[$p['sex']] ?? 0) + 1;
            $byBand[$p['band']]++;
        }
        arsort($bySex);

        uasort($t['treatments'], fn ($a, $b) => $b['times'] <=> $a['times'] ?: bccomp($b['amount'], $a['amount'], 2));
        arsort($t['diagnoses']);
        ksort($t['days']);

        $names = User::query()->whereIn('id', array_filter(array_keys($t['clinicians'])))->pluck('name', 'id');
        $clinicians = [];
        foreach ($t['clinicians'] as $id => $c) {
            $clinicians[] = ['clinician' => $id === 0 ? 'Not recorded' : (string) ($names[$id] ?? 'Unknown'), 'visits' => $c['visits'], 'patients' => count($c['patients']), 'billed' => $c['billed']];
        }
        usort($clinicians, fn ($a, $b) => $b['visits'] <=> $a['visits']);

        $int = fn (string $key, string $label) => ['key' => $key, 'label' => $label, 'type' => 'int'];

        return [
            'figures' => [
                ['label' => 'Patients seen', 'value' => $patients, 'type' => 'int', 'note' => $t['visits'].' visits'],
                ['label' => 'New', 'value' => $t['new'], 'type' => 'int', 'note' => 'first visit here'],
                ['label' => 'Returning', 'value' => $t['visits'] - $t['new'], 'type' => 'int', 'note' => 'visits'],
                ['label' => 'Billed', 'value' => $t['billed'], 'type' => 'money', 'note' => 'on these visits'],
                ['label' => 'Balance', 'value' => $t['balance'], 'type' => 'money', 'note' => 'still owed on them'],
            ],
            'tables' => [
                [
                    'key' => 'attendance', 'title' => 'New and returning',
                    'columns' => [['key' => 'attendance', 'label' => 'Visit', 'type' => 'text'], $int('visits', 'Visits')],
                    'rows' => [['attendance' => 'New', 'visits' => $t['new']], ['attendance' => 'Returning', 'visits' => $t['visits'] - $t['new']]],
                    'total' => ['attendance' => 'Total', 'visits' => $t['visits']],
                ],
                [
                    'key' => 'by_sex', 'title' => 'By sex',
                    'columns' => [['key' => 'sex', 'label' => 'Sex', 'type' => 'text'], $int('patients', 'Patients')],
                    'rows' => array_map(fn ($s, $n) => ['sex' => (string) $s, 'patients' => $n], array_keys($bySex), array_values($bySex)),
                    'total' => ['sex' => 'Total', 'patients' => $patients],
                ],
                [
                    'key' => 'by_age', 'title' => 'By age',
                    'columns' => [['key' => 'band', 'label' => 'Age band', 'type' => 'text'], $int('patients', 'Patients')],
                    'rows' => array_map(fn ($b, $n) => ['band' => (string) $b, 'patients' => $n], array_keys($byBand), array_values($byBand)),
                    'total' => ['band' => 'Total', 'patients' => $patients],
                ],
                [
                    'key' => 'treatments', 'title' => 'Top treatments',
                    'columns' => [['key' => 'treatment', 'label' => 'Treatment', 'type' => 'text'], $int('times', 'Times'), ['key' => 'amount', 'label' => 'Billed', 'type' => 'money']],
                    'rows' => array_slice(array_map(fn ($n, $v) => ['treatment' => (string) $n, 'times' => $v['times'], 'amount' => $v['amount']], array_keys($t['treatments']), array_values($t['treatments'])), 0, 15),
                ],
                ...($this->clinical ? [[
                    'key' => 'diagnoses', 'title' => 'Top diagnoses',
                    'columns' => [['key' => 'diagnosis', 'label' => 'Diagnosis', 'type' => 'text'], $int('visits', 'Visits')],
                    'rows' => array_slice(array_map(fn ($n, $c) => ['diagnosis' => (string) $n, 'visits' => $c], array_keys($t['diagnoses']), array_values($t['diagnoses'])), 0, 15),
                ]] : []),
                [
                    'key' => 'by_clinician', 'title' => 'By '.mb_strtolower($clinician),
                    'columns' => [['key' => 'clinician', 'label' => $clinician, 'type' => 'text'], $int('visits', 'Visits'), $int('patients', 'Patients'), ['key' => 'billed', 'label' => 'Billed', 'type' => 'money']],
                    'rows' => $clinicians,
                    'total' => ['clinician' => 'Total', 'visits' => $t['visits'], 'patients' => $patients, 'billed' => $t['billed']],
                ],
                [
                    'key' => 'by_day', 'title' => 'Visits per day',
                    'columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], $int('visits', 'Visits')],
                    'rows' => array_map(fn ($d, $n) => ['date' => (string) $d, 'visits' => $n], array_keys($t['days']), array_values($t['days'])),
                    'total' => ['date' => 'Total', 'visits' => $t['visits']],
                ],
            ],
        ];
    }
}
