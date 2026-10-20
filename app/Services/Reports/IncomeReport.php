<?php

namespace App\Services\Reports;

use App\Enums\CreditKind;
use App\Enums\CreditStatus;
use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\InvoiceCredit;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Income: every payment taken in the period, and every refund paid out, as a
 * negative line (docs/refunds.md).
 *
 * The same rows the cashier's day adds up (CashierDaySummary): payments by
 * when they were taken, refunds by when they were approved, the hospital's own
 * days. So the month is exactly the sum of its cashier's days, and the net is
 * what the drawers took less what they gave back. A credit note moves no
 * money; it is counted beside the figures, never in them.
 */
class IncomeReport extends MonthlyReport
{
    public function title(): string
    {
        return 'Income report';
    }

    public function columns(): array
    {
        return [
            ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'receipt_no', 'label' => 'Receipt no', 'type' => 'text'],
            ['key' => 'patient_no', 'label' => 'Patient no', 'type' => 'text'],
            ['key' => 'patient', 'label' => 'Patient', 'type' => 'text'],
            ['key' => 'services', 'label' => 'Paid for', 'type' => 'text'],
            ['key' => 'invoice_no', 'label' => 'Invoice no', 'type' => 'text'],
            ['key' => 'method', 'label' => 'Method', 'type' => 'text'],
            ['key' => 'clinician', 'label' => self::clinician(), 'type' => 'text'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'],
        ];
    }

    /** @return Builder<Payment> */
    private function payments(ReportPeriod $p): Builder
    {
        return Payment::query()->where('payments.created_at', '>=', $p->start())->where('payments.created_at', '<', $p->end());
    }

    /** @return Builder<InvoiceCredit> */
    private function credits(ReportPeriod $p, CreditKind $kind): Builder
    {
        return InvoiceCredit::query()->where('status', CreditStatus::Approved->value)->where('kind', $kind->value)
            ->where('decided_at', '>=', $p->start())->where('decided_at', '<', $p->end());
    }

    /** @return array<int|string, mixed> */
    private function invoiceLoads(): array
    {
        return [
            'invoice' => fn ($q) => $q->withTrashed()->select(['id', 'invoice_no', 'visit_id', 'patient_id', 'opening_balance']),
            'invoice.items:id,invoice_id,description',
            'invoice.visit:id,doctor_user_id',
            'invoice.visit.doctor:id,name',
            'invoice.patient:id,patient_no,first_name,last_name',
        ];
    }

    public function rows(ReportPeriod $period, int $offset = 0, ?int $limit = null): \Generator
    {
        // Payments and refunds as one list in time order, paged in the database.
        $payments = $this->payments($period)->toBase()->selectRaw("'p' as src, payments.id as id, payments.created_at as at");
        $refunds = $this->credits($period, CreditKind::Refund)->toBase()->selectRaw("'r' as src, invoice_credits.id as id, invoice_credits.decided_at as at");
        $order = DB::query()->fromSub($payments->unionAll($refunds), 'u')->orderBy('at')->orderBy('src')->orderBy('id');

        foreach (self::window($order, $offset, $limit) as $chunk) {
            $ids = fn (string $src) => $chunk->where('src', $src)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $pays = Payment::query()->with($this->invoiceLoads())->whereIn('id', $ids('p'))->get()->keyBy('id');
            $refs = InvoiceCredit::query()->with($this->invoiceLoads())->whereIn('id', $ids('r'))->get()->keyBy('id');

            foreach ($chunk as $ref) {
                if ($ref->src === 'p' && ($pay = $pays->get((int) $ref->id)) !== null) {
                    yield $this->row(
                        $period->localDate($pay->created_at),
                        'R'.strtoupper(substr(str_replace('-', '', (string) $pay->uuid), 0, 8)),
                        $pay->invoice,
                        $pay->method->label(),
                        (string) $pay->amount,
                    );
                } elseif ($ref->src === 'r' && ($c = $refs->get((int) $ref->id)) !== null) {
                    yield $this->row(
                        $period->localDate($c->decided_at),
                        (string) $c->number,
                        $c->invoice,
                        'Refund, '.($c->method ?? PaymentMethod::Cash)->label(),
                        bcmul((string) $c->amount, '-1', 2),
                    );
                }
            }
        }
    }

    /** @return array<string, string|int|null> */
    private function row(string $date, string $receipt, ?Invoice $invoice, string $method, string $amount): array
    {
        return [
            'date' => $date,
            'receipt_no' => $receipt,
            'patient_no' => $invoice?->patient?->patient_no,
            'patient' => $invoice?->patient?->full_name,
            'services' => $this->services($invoice),
            'invoice_no' => $invoice?->invoice_no,
            'method' => $method,
            'clinician' => $invoice?->visit?->doctor?->name,
            'amount' => $amount,
        ];
    }

    private function services(?Invoice $invoice): string
    {
        if ($invoice === null) {
            return '';
        }
        if ($invoice->opening_balance) {
            return 'Balance brought forward';
        }

        return $invoice->items->pluck('description')->filter()->unique()->implode(', ');
    }

    public function count(ReportPeriod $period): int
    {
        return $this->payments($period)->count() + $this->credits($period, CreditKind::Refund)->count();
    }

    public function totals(ReportPeriod $period): array
    {
        $out = [];
        foreach ($this->byMethod($period) as $m) {
            $out[] = ['label' => $m['method'], 'value' => $m['net'], 'type' => 'money'];
        }
        $t = $this->headline($period);
        $out[] = ['label' => 'Received', 'value' => $t['received'], 'type' => 'money'];
        $out[] = ['label' => 'Refunded', 'value' => bcmul($t['refunded'], '-1', 2), 'type' => 'money'];
        $out[] = ['label' => 'Net income', 'value' => $t['net'], 'type' => 'money'];

        return $out;
    }

    /** @return array{received:string,payments:int,refunded:string,refunds:int,net:string,credited:string,credit_notes:int} */
    public function headline(ReportPeriod $period): array
    {
        $pay = $this->payments($period)->toBase()->selectRaw('COUNT(*) as n, COALESCE(SUM(amount), 0) as s')->first();
        $ref = $this->credits($period, CreditKind::Refund)->toBase()->selectRaw('COUNT(*) as n, COALESCE(SUM(amount), 0) as s')->first();
        $cn = $this->credits($period, CreditKind::CreditNote)->toBase()->selectRaw('COUNT(*) as n, COALESCE(SUM(amount), 0) as s')->first();

        $received = self::dec($pay->s ?? 0);
        $refunded = self::dec($ref->s ?? 0);

        return [
            'received' => $received,
            'payments' => (int) ($pay->n ?? 0),
            'refunded' => $refunded,
            'refunds' => (int) ($ref->n ?? 0),
            'net' => bcsub($received, $refunded, 2),
            'credited' => self::dec($cn->s ?? 0),
            'credit_notes' => (int) ($cn->n ?? 0),
        ];
    }

    /** @return list<array{method:string,payments:int,received:string,refunded:string,net:string}> */
    private function byMethod(ReportPeriod $period): array
    {
        $rows = [];
        foreach ($this->payments($period)->toBase()->selectRaw('method, COUNT(*) as n, COALESCE(SUM(amount), 0) as s')->groupBy('method')->get() as $r) {
            $rows[(string) $r->method] = ['payments' => (int) $r->n, 'received' => self::dec($r->s), 'refunded' => '0.00'];
        }
        foreach ($this->credits($period, CreditKind::Refund)->toBase()->selectRaw('method, COALESCE(SUM(amount), 0) as s')->groupBy('method')->get() as $r) {
            $m = (string) ($r->method ?: PaymentMethod::Cash->value);
            $rows[$m] ??= ['payments' => 0, 'received' => '0.00', 'refunded' => '0.00'];
            $rows[$m]['refunded'] = bcadd($rows[$m]['refunded'], self::dec($r->s), 2);
        }

        $out = [];
        foreach ($rows as $method => $r) {
            $out[] = [
                'method' => PaymentMethod::tryFrom($method)?->label() ?? $method,
                'payments' => $r['payments'],
                'received' => $r['received'],
                'refunded' => $r['refunded'],
                'net' => bcsub($r['received'], $r['refunded'], 2),
            ];
        }
        usort($out, fn ($a, $b) => bccomp($b['net'], $a['net'], 2));

        return $out;
    }

    public function summary(ReportPeriod $period): array
    {
        $t = $this->headline($period);
        $prev = $this->headline($period->previous());

        // One pass over the period's payments, a chunk at a time, for what the
        // database cannot group by: the hospital's own day, the service each
        // payment paid for, and who saw the patient.
        $days = [];
        $services = [];
        $clinicians = [];
        $this->payments($period)->select(['payments.id', 'payments.invoice_id', 'payments.amount', 'payments.created_at'])
            ->with([
                'invoice' => fn ($q) => $q->withTrashed()->select(['id', 'visit_id', 'opening_balance']),
                'invoice.items:id,invoice_id,description,line_total',
                'invoice.visit:id,doctor_user_id',
            ])
            ->chunkById(500, function ($chunk) use (&$days, &$services, &$clinicians, $period) {
                foreach ($chunk as $pay) {
                    $amount = (string) $pay->amount;
                    $day = $period->localDate($pay->created_at);
                    $days[$day] ??= ['received' => '0.00', 'refunded' => '0.00'];
                    $days[$day]['received'] = bcadd($days[$day]['received'], $amount, 2);

                    foreach ($this->allocate($amount, $pay->invoice) as $name => $share) {
                        $services[$name] = bcadd($services[$name] ?? '0.00', $share, 2);
                    }

                    $who = (int) ($pay->invoice?->visit->doctor_user_id ?? 0);
                    $clinicians[$who] ??= ['payments' => 0, 'received' => '0.00', 'refunded' => '0.00'];
                    $clinicians[$who]['payments']++;
                    $clinicians[$who]['received'] = bcadd($clinicians[$who]['received'], $amount, 2);
                }
            }, 'payments.id', 'id');

        foreach ($this->credits($period, CreditKind::Refund)->with(['invoice' => fn ($q) => $q->withTrashed()->select(['id', 'visit_id']), 'invoice.visit:id,doctor_user_id'])
            ->lazyById(500) as $c) {
            $amount = (string) $c->amount;
            $day = $period->localDate($c->decided_at);
            $days[$day] ??= ['received' => '0.00', 'refunded' => '0.00'];
            $days[$day]['refunded'] = bcadd($days[$day]['refunded'], $amount, 2);

            $who = (int) ($c->invoice?->visit->doctor_user_id ?? 0);
            $clinicians[$who] ??= ['payments' => 0, 'received' => '0.00', 'refunded' => '0.00'];
            $clinicians[$who]['refunded'] = bcadd($clinicians[$who]['refunded'], $amount, 2);
        }

        ksort($days);
        uasort($services, fn ($a, $b) => bccomp($b, $a, 2));
        $names = User::query()->whereIn('id', array_filter(array_keys($clinicians)))->pluck('name', 'id');
        $clinician = self::clinician();

        $money = fn (string $key, string $label) => ['key' => $key, 'label' => $label, 'type' => 'money'];

        $methodRows = $this->byMethod($period);
        $clinicianRows = [];
        foreach ($clinicians as $id => $c) {
            $clinicianRows[] = [
                'clinician' => $id === 0 ? 'Not recorded' : (string) ($names[$id] ?? 'Unknown'),
                'payments' => $c['payments'],
                'received' => $c['received'],
                'refunded' => $c['refunded'],
                'net' => bcsub($c['received'], $c['refunded'], 2),
            ];
        }
        usort($clinicianRows, fn ($a, $b) => bccomp($b['net'], $a['net'], 2));

        $totalRow = ['received' => $t['received'], 'refunded' => $t['refunded'], 'net' => $t['net']];

        return [
            'figures' => [
                ['label' => 'Received', 'value' => $t['received'], 'type' => 'money', 'note' => $t['payments'].' payments'],
                ['label' => 'Refunded', 'value' => $t['refunded'], 'type' => 'money', 'note' => $t['refunds'].' refunds'],
                ['label' => 'Net income', 'value' => $t['net'], 'type' => 'money', 'note' => 'received less refunded'],
                ['label' => 'Credit notes', 'value' => $t['credited'], 'type' => 'money', 'note' => $t['credit_notes'].' issued, no money moved'],
                ['label' => 'Change in net income', 'value' => self::change($t['net'], $prev['net']), 'type' => 'percent', 'note' => 'against '.$period->previous()->label()],
            ],
            'tables' => [
                [
                    'key' => 'by_method', 'title' => 'By method',
                    'columns' => [['key' => 'method', 'label' => 'Method', 'type' => 'text'], ['key' => 'payments', 'label' => 'Payments', 'type' => 'int'], $money('received', 'Received'), $money('refunded', 'Refunded'), $money('net', 'Net')],
                    'rows' => $methodRows,
                    'total' => ['method' => 'Total', 'payments' => $t['payments']] + $totalRow,
                ],
                [
                    'key' => 'by_day', 'title' => 'By day',
                    'columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], $money('received', 'Received'), $money('refunded', 'Refunded'), $money('net', 'Net')],
                    'rows' => array_map(fn ($d, $v) => ['date' => (string) $d, 'received' => $v['received'], 'refunded' => $v['refunded'], 'net' => bcsub($v['received'], $v['refunded'], 2)], array_keys($days), array_values($days)),
                    'total' => ['date' => 'Total'] + $totalRow,
                ],
                [
                    'key' => 'by_service', 'title' => 'By service',
                    'note' => 'A payment is shared across the lines of the bill it paid, in proportion to each line. Refunds are not shared out.',
                    'columns' => [['key' => 'service', 'label' => 'Service', 'type' => 'text'], $money('received', 'Received')],
                    'rows' => array_map(fn ($n, $v) => ['service' => (string) $n, 'received' => $v], array_keys($services), array_values($services)),
                    'total' => ['service' => 'Total', 'received' => $t['received']],
                ],
                [
                    'key' => 'by_clinician', 'title' => 'By '.mb_strtolower($clinician),
                    'columns' => [['key' => 'clinician', 'label' => $clinician, 'type' => 'text'], ['key' => 'payments', 'label' => 'Payments', 'type' => 'int'], $money('received', 'Received'), $money('refunded', 'Refunded'), $money('net', 'Net')],
                    'rows' => $clinicianRows,
                    'total' => ['clinician' => 'Total', 'payments' => $t['payments']] + $totalRow,
                ],
                self::comparison([
                    'Received' => [$t['received'], $prev['received']],
                    'Refunded' => [$t['refunded'], $prev['refunded']],
                    'Net income' => [$t['net'], $prev['net']],
                ], $period),
            ],
        ];
    }

    /**
     * A payment shared across its bill's lines in proportion to each line,
     * to the cent; the last line takes what rounding left, so the shares add
     * up to the payment exactly.
     *
     * @return array<string,string> service => share
     */
    private function allocate(string $amount, ?Invoice $invoice): array
    {
        if ($invoice === null) {
            return ['Not itemised' => $amount];
        }
        if ($invoice->opening_balance) {
            return ['Balance brought forward' => $amount];
        }

        $lines = $invoice->items->filter(fn ($i) => bccomp((string) $i->line_total, '0', 2) > 0)->values();
        $base = self::sum($lines->map(fn ($i) => (string) $i->line_total)->all());
        if ($lines->isEmpty() || bccomp($base, '0', 2) <= 0) {
            return ['Not itemised' => $amount];
        }

        $out = [];
        $given = '0.00';
        $last = $lines->count() - 1;
        foreach ($lines as $i => $line) {
            $share = $i === $last
                ? bcsub($amount, $given, 2)
                : bcdiv(bcmul($amount, (string) $line->line_total, 6), $base, 2);
            $given = bcadd($given, $share, 2);
            $name = trim((string) $line->description) ?: 'Not itemised';
            $out[$name] = bcadd($out[$name] ?? '0.00', $share, 2);
        }

        return $out;
    }
}
