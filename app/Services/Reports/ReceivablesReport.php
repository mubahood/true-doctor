<?php

namespace App\Services\Reports;

use App\Enums\CreditKind;
use App\Enums\CreditStatus;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceCredit;
use App\Models\Payment;
use App\Services\DebtorsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Accounts receivable: every invoice with money owed on it at the end of the
 * period, aged in the debt collector's bands (DebtorsService::BUCKETS).
 *
 * While the period is still running "the end" is now, and the list is
 * exactly the debtors list: Invoice::outstanding(), its stored balance, its
 * age in days since it was raised. For a period that has ended, each balance
 * is worked back to that day from the invoice's own payments, refunds and
 * credit notes (balance = total less credited less what was held), the
 * formula BillingService and InvoiceCreditService keep the stored balance by.
 * A void invoice is never counted, whenever it was voided.
 */
class ReceivablesReport extends MonthlyReport
{
    public function title(): string
    {
        return 'Accounts receivable report';
    }

    public function columns(): array
    {
        return [
            ['key' => 'patient_no', 'label' => 'Patient no', 'type' => 'text'],
            ['key' => 'patient', 'label' => 'Patient', 'type' => 'text'],
            ['key' => 'phone', 'label' => 'Phone', 'type' => 'text'],
            ['key' => 'invoice_no', 'label' => 'Invoice no', 'type' => 'text'],
            ['key' => 'invoice_date', 'label' => 'Invoice date', 'type' => 'date'],
            ['key' => 'total', 'label' => 'Invoice total', 'type' => 'money'],
            ['key' => 'paid', 'label' => 'Paid', 'type' => 'money'],
            ['key' => 'credited', 'label' => 'Credited', 'type' => 'money'],
            ['key' => 'balance', 'label' => 'Balance', 'type' => 'money'],
            ['key' => 'days', 'label' => 'Days outstanding', 'type' => 'int'],
            ['key' => 'band', 'label' => 'Age band', 'type' => 'text'],
        ];
    }

    /** The band as people say it. */
    public static function bandLabel(string $band): string
    {
        return $band === '90+' ? 'Over 90 days' : $band.' days';
    }

    /**
     * Invoices that may have been owing at `$at`.
     *
     * @return Builder<Invoice>
     */
    private function candidates(Carbon $at, bool $current): Builder
    {
        if ($current) {
            return Invoice::query()->outstanding()->select('invoices.*');
        }

        $credit = fn (CreditKind $kind) => InvoiceCredit::query()->selectRaw('COALESCE(SUM(amount), 0)')
            ->whereColumn('invoice_credits.invoice_id', 'invoices.id')
            ->where('status', CreditStatus::Approved->value)->where('kind', $kind->value)
            ->where('decided_at', '<', $at);

        return Invoice::query()
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Void->value])
            ->where('invoices.created_at', '<', $at)
            // Owing now, or moved since: anything else was settled then and still is.
            ->where(fn ($q) => $q->where('balance', '>', 0)
                ->orWhereIn('invoices.id', Payment::query()->where('created_at', '>=', $at)->select('invoice_id'))
                ->orWhereIn('invoices.id', InvoiceCredit::query()->where('status', CreditStatus::Approved->value)->where('decided_at', '>=', $at)->select('invoice_id')))
            ->select('invoices.*')
            ->addSelect([
                'ar_paid' => Payment::query()->selectRaw('COALESCE(SUM(amount), 0)')->whereColumn('payments.invoice_id', 'invoices.id')->where('created_at', '<', $at),
                'ar_refunded' => $credit(CreditKind::Refund),
                'ar_credited' => $credit(CreditKind::CreditNote),
            ]);
    }

    /**
     * Every invoice owing at `$at`, oldest first, with its figures then.
     * `$current` when `$at` is now: the stored figures are the answer.
     *
     * @return \Generator<int, array{invoice:Invoice,total:string,paid:string,credited:string,balance:string,days:int,band:string}>
     */
    private function owing(Carbon $at, bool $current, int $offset = 0, ?int $limit = null): \Generator
    {
        $query = $this->candidates($at, $current)->with('patient:id,patient_no,first_name,last_name,phone_1')
            ->orderBy('invoices.created_at')->orderBy('invoices.id');

        // While the period runs every candidate is owing, so the database
        // pages them; for an ended one each balance is worked out first.
        $skip = $current ? 0 : $offset;
        $given = 0;
        foreach (self::window($query, $current ? $offset : 0, $current ? $limit : null) as $chunk) {
            foreach ($chunk as $invoice) {
                foreach ($this->figures($invoice, $at, $current) as $o) {
                    if ($skip > 0) {
                        $skip--;

                        continue;
                    }
                    if ($limit !== null && $given >= $limit) {
                        return;
                    }
                    $given++;
                    yield $o;
                }
            }
        }
    }

    /**
     * One invoice's figures at `$at`: nothing when it owed nothing then.
     *
     * @return list<array{invoice:Invoice,total:string,paid:string,credited:string,balance:string,days:int,band:string}>
     */
    private function figures(Invoice $invoice, Carbon $at, bool $current): array
    {
        $total = (string) $invoice->total;
        if ($current) {
            $credited = (string) $invoice->credited;
            $paid = $invoice->held();
            $balance = (string) $invoice->balance;
        } else {
            $credited = self::dec($invoice->getAttribute('ar_credited'));
            $paid = bcsub(self::dec($invoice->getAttribute('ar_paid')), self::dec($invoice->getAttribute('ar_refunded')), 2);
            $balance = bcsub(bcsub($total, $credited, 2), $paid, 2);
        }

        if (bccomp($balance, '0', 2) <= 0) {
            return [];
        }

        $days = (int) $invoice->created_at->diffInDays($at);

        return [[
            'invoice' => $invoice,
            'total' => $total,
            'paid' => $paid,
            'credited' => $credited,
            'balance' => $balance,
            'days' => $days,
            'band' => DebtorsService::bucketOf($days),
        ]];
    }

    /** Whether the period has not ended yet, so its end is now. */
    private static function running(ReportPeriod $period): bool
    {
        return $period->end()->gt(Carbon::now());
    }

    public function rows(ReportPeriod $period, int $offset = 0, ?int $limit = null): \Generator
    {
        foreach ($this->owing($period->asAt(), self::running($period), $offset, $limit) as $o) {
            $p = $o['invoice']->patient;
            yield [
                'patient_no' => $p?->patient_no,
                'patient' => $p?->full_name,
                'phone' => $p?->phone_1,
                'invoice_no' => $o['invoice']->invoice_no,
                'invoice_date' => $period->localDate($o['invoice']->created_at),
                'total' => $o['total'],
                'paid' => $o['paid'],
                'credited' => $o['credited'],
                'balance' => $o['balance'],
                'days' => $o['days'],
                'band' => self::bandLabel($o['band']),
            ];
        }
    }

    public function count(ReportPeriod $period): int
    {
        if (self::running($period)) {
            return Invoice::query()->outstanding()->count();
        }

        return iterator_count($this->owing($period->asAt(), false));
    }

    /**
     * What was owed at a moment: in total, by band, and by patient.
     *
     * @return array{total:string,invoices:int,bands:array<string,array{invoices:int,amount:string}>,patients:array<int,array{patient:?\App\Models\Patient,invoices:int,balance:string,days:int}>}
     */
    public function position(Carbon $at, bool $current): array
    {
        $bands = [];
        foreach (DebtorsService::BUCKETS as $b) {
            $bands[$b] = ['invoices' => 0, 'amount' => '0.00'];
        }
        $total = '0.00';
        $count = 0;
        $patients = [];

        foreach ($this->owing($at, $current) as $o) {
            $total = bcadd($total, $o['balance'], 2);
            $count++;
            $bands[$o['band']]['invoices']++;
            $bands[$o['band']]['amount'] = bcadd($bands[$o['band']]['amount'], $o['balance'], 2);

            $pid = (int) $o['invoice']->patient_id;
            $patients[$pid] ??= ['patient' => $o['invoice']->patient, 'invoices' => 0, 'balance' => '0.00', 'days' => 0];
            $patients[$pid]['invoices']++;
            $patients[$pid]['balance'] = bcadd($patients[$pid]['balance'], $o['balance'], 2);
            $patients[$pid]['days'] = max($patients[$pid]['days'], $o['days']);
        }

        return ['total' => $total, 'invoices' => $count, 'bands' => $bands, 'patients' => $patients];
    }

    public function totals(ReportPeriod $period): array
    {
        $pos = $this->position($period->asAt(), self::running($period));
        $out = [];
        foreach ($pos['bands'] as $band => $b) {
            $out[] = ['label' => self::bandLabel($band), 'value' => $b['amount'], 'type' => 'money'];
        }
        $out[] = ['label' => 'Total owed', 'value' => $pos['total'], 'type' => 'money'];

        return $out;
    }

    public function summary(ReportPeriod $period): array
    {
        $at = $period->asAt();
        $end = $this->position($at, self::running($period));
        $started = $period->start()->gt(Carbon::now());
        $start = $this->position($started ? Carbon::now() : $period->start(), $started);

        // Paid in the period on bills raised before it began.
        $collected = self::dec(Payment::query()
            ->where('created_at', '>=', $period->start())->where('created_at', '<', $period->end())
            ->whereIn('invoice_id', Invoice::query()->where('created_at', '<', $period->start())->select('id'))
            ->sum('amount'));
        $share = bccomp($start['total'], '0', 2) > 0 ? bcdiv(bcmul($collected, '100', 4), $start['total'], 1) : null;

        // New bills only: a balance brought forward from the old books is not this period's billing.
        $billed = self::dec(Invoice::query()->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Void->value])->where('opening_balance', false)
            ->where('created_at', '>=', $period->start())->where('created_at', '<', $period->end())->sum('total'));

        $top = $end['patients'];
        uasort($top, fn ($a, $b) => bccomp($b['balance'], $a['balance'], 2));
        $top = array_slice($top, 0, 10);

        // The end is the first moment after the last day: the day before it is the one to name.
        $asAtLabel = $at->copy()->subSecond()->setTimezone($period->zone)->format('j M Y');

        return [
            'figures' => [
                ['label' => 'Owed', 'value' => $end['total'], 'type' => 'money', 'note' => 'as at '.$asAtLabel],
                ['label' => 'Debtors', 'value' => count($end['patients']), 'type' => 'int', 'note' => $end['invoices'].' invoices'],
                ['label' => 'Over 90 days', 'value' => $end['bands']['90+']['amount'], 'type' => 'money', 'note' => $end['bands']['90+']['invoices'].' invoices'],
                ['label' => 'Collected from old bills', 'value' => $collected, 'type' => 'money', 'note' => $share === null ? 'nothing was owed at the start' : $share.'% of what was owed at the start'],
            ],
            'tables' => [
                [
                    'key' => 'by_band', 'title' => 'By age',
                    'columns' => [['key' => 'band', 'label' => 'Age band', 'type' => 'text'], ['key' => 'invoices', 'label' => 'Invoices', 'type' => 'int'], ['key' => 'amount', 'label' => 'Owed', 'type' => 'money']],
                    'rows' => array_map(fn ($b, $v) => ['band' => self::bandLabel((string) $b), 'invoices' => $v['invoices'], 'amount' => $v['amount']], array_keys($end['bands']), array_values($end['bands'])),
                    'total' => ['band' => 'Total', 'invoices' => $end['invoices'], 'amount' => $end['total']],
                ],
                [
                    'key' => 'largest', 'title' => 'Ten largest balances',
                    'columns' => [
                        ['key' => 'patient_no', 'label' => 'Patient no', 'type' => 'text'],
                        ['key' => 'patient', 'label' => 'Patient', 'type' => 'text'],
                        ['key' => 'phone', 'label' => 'Phone', 'type' => 'text'],
                        ['key' => 'invoices', 'label' => 'Invoices', 'type' => 'int'],
                        ['key' => 'days', 'label' => 'Oldest, days', 'type' => 'int'],
                        ['key' => 'balance', 'label' => 'Owed', 'type' => 'money'],
                    ],
                    'rows' => array_map(fn ($p) => [
                        'patient_no' => $p['patient']?->patient_no,
                        'patient' => $p['patient']?->full_name,
                        'phone' => $p['patient']?->phone_1,
                        'invoices' => $p['invoices'],
                        'days' => $p['days'],
                        'balance' => $p['balance'],
                    ], $top),
                ],
                [
                    'key' => 'collection', 'title' => 'Collection in the period',
                    'columns' => [['key' => 'figure', 'label' => 'Figure', 'type' => 'text'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money']],
                    'rows' => [
                        ['figure' => 'Owed at the start of the period', 'amount' => $start['total']],
                        ['figure' => 'Collected in the period from what was owed at the start', 'amount' => $collected],
                        ['figure' => 'Billed in the period', 'amount' => $billed],
                        ['figure' => 'Owed at the end of the period', 'amount' => $end['total']],
                    ],
                ] + ($share === null ? [] : ['note' => $share.'% of what was owed at the start was collected in the period.']),
            ],
        ];
    }
}
