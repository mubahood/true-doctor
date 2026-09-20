<?php

namespace App\Services;

use App\Enums\FinancialYearStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\ClosedPeriodException;
use App\Exceptions\FinancialYearException;
use App\Models\FinancialYear;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\CurrentHospital;
use Illuminate\Support\Carbon;

/**
 * Accounting periods + per-period reporting. Periods may not overlap. Closing a
 * period freezes it: assertPostingAllowed() (called from invoice generation)
 * rejects a posting dated inside a closed period. Reporting rolls up payments
 * and invoices within the period in bcmath.
 */
class FinancialYearService
{
    public function create(array $data): FinancialYear
    {
        $start = Carbon::parse($data['starts_on']);
        $end = Carbon::parse($data['ends_on']);
        if ($end->lt($start)) {
            throw FinancialYearException::invalidRange();
        }

        // No overlap with an existing period in this hospital.
        $overlap = FinancialYear::where('starts_on', '<=', $end->toDateString())
            ->where('ends_on', '>=', $start->toDateString())
            ->exists();
        if ($overlap) {
            throw FinancialYearException::overlapping();
        }

        return FinancialYear::create([
            'name' => $data['name'],
            'starts_on' => $start->toDateString(),
            'ends_on' => $end->toDateString(),
            'status' => FinancialYearStatus::Open,
        ]);
    }

    public function close(FinancialYear $year, ?int $by = null): FinancialYear
    {
        if (! $year->isOpen()) {
            throw FinancialYearException::alreadyClosed();
        }
        $year->update(['status' => FinancialYearStatus::Closed, 'closed_at' => Carbon::now(), 'closed_by' => $by]);

        return $year;
    }

    public function reopen(FinancialYear $year): FinancialYear
    {
        $year->update(['status' => FinancialYearStatus::Open, 'closed_at' => null, 'closed_by' => null]);

        return $year;
    }

    /** Guard used at posting time — throws if the date lands in a closed period. */
    public function assertPostingAllowed(Carbon $date): void
    {
        if (app(CurrentHospital::class)->id() === null) {
            return;
        }

        $closed = FinancialYear::where('status', FinancialYearStatus::Closed->value)
            ->where('starts_on', '<=', $date->toDateString())
            ->where('ends_on', '>=', $date->toDateString())
            ->first();

        if ($closed !== null) {
            throw ClosedPeriodException::make($closed->name);
        }
    }

    /**
     * @return array{payments_total:string,invoices_total:string,outstanding:string,by_method:array<string,string>,invoice_count:int,payment_count:int}
     */
    public function report(FinancialYear $year): array
    {
        $from = $year->starts_on->startOfDay();
        $to = $year->ends_on->endOfDay();

        $payments = Payment::whereBetween('created_at', [$from, $to])->get(['method', 'amount']);
        $paymentsTotal = '0.00';
        $byMethod = [];
        foreach (PaymentMethod::cases() as $m) {
            $byMethod[$m->value] = '0.00';
        }
        foreach ($payments as $p) {
            $paymentsTotal = bcadd($paymentsTotal, (string) $p->amount, 2);
            $byMethod[$p->method->value] = bcadd($byMethod[$p->method->value], (string) $p->amount, 2);
        }

        $invoices = Invoice::whereBetween('issued_at', [$from, $to])->get(['total', 'balance']);
        $invoicesTotal = '0.00';
        $outstanding = '0.00';
        foreach ($invoices as $inv) {
            $invoicesTotal = bcadd($invoicesTotal, (string) $inv->total, 2);
            $outstanding = bcadd($outstanding, (string) $inv->balance, 2);
        }

        return [
            'payments_total' => $paymentsTotal,
            'invoices_total' => $invoicesTotal,
            'outstanding' => $outstanding,
            'by_method' => array_filter($byMethod, fn ($v) => bccomp($v, '0', 2) > 0),
            'invoice_count' => $invoices->count(),
            'payment_count' => $payments->count(),
        ];
    }
}
