<?php

namespace App\Services;

use App\Enums\CreditKind;
use App\Enums\CreditStatus;
use App\Enums\InvoiceStatus;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\InvoiceCredit;
use App\Models\Payment;
use App\Support\HospitalSettings;
use Illuminate\Support\Carbon;

/**
 * A cashier's day, as the figures a till is closed against: what came in by
 * method and by person, how many invoices were raised, how many voided.
 *
 * "The day" is the HOSPITAL's calendar day (its time zone), converted to the
 * clock the database stores (`app.timezone`) for the query, so a clinic in
 * Kampala closing at 18:00 does not lose the afternoon to UTC.
 */
class CashierDaySummary
{
    public function __construct(private readonly ReminderService $zones) {}

    public function today(?Hospital $hospital): string
    {
        return now($this->zone($hospital))->toDateString();
    }

    /** @return array<string,mixed> */
    public function forDate(?Hospital $hospital, string $date): array
    {
        $tz = $this->zone($hospital);
        $from = Carbon::createFromFormat('Y-m-d', $date, $tz)->startOfDay()->setTimezone(config('app.timezone'));
        $to = $from->copy()->addDay();

        $payments = Payment::query()->with('receivedBy:id,name')
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->get(['id', 'method', 'amount', 'received_by', 'created_at']);

        $total = '0.00';
        $methods = [];
        $users = [];
        foreach ($payments as $p) {
            $amount = (string) $p->amount;
            $total = bcadd($total, $amount, 2);

            $m = $p->method->value;
            $methods[$m] ??= ['method' => $m, 'count' => 0, 'amount' => '0.00'];
            $methods[$m]['count']++;
            $methods[$m]['amount'] = bcadd($methods[$m]['amount'], $amount, 2);

            $name = $p->receivedBy?->name ?: 'Unknown';
            $users[$name] = bcadd($users[$name] ?? '0.00', $amount, 2);
        }

        usort($methods, fn ($a, $b) => bccomp($b['amount'], $a['amount'], 2));
        arsort($users);

        // Money given back the same day (docs/refunds.md): a refund leaves
        // the drawer, a credit note only lowers what is owed.
        $decided = InvoiceCredit::query()->where('status', CreditStatus::Approved->value)
            ->where('decided_at', '>=', $from)->where('decided_at', '<', $to)
            ->get(['id', 'kind', 'method', 'amount']);
        $refunds = ['count' => 0, 'total' => '0.00', 'by_method' => []];
        $credits = ['count' => 0, 'total' => '0.00'];
        foreach ($decided as $c) {
            $amount = (string) $c->amount;
            if ($c->kind === CreditKind::Refund) {
                $refunds['count']++;
                $refunds['total'] = bcadd($refunds['total'], $amount, 2);
                $m = $c->method->value ?? 'cash';
                $refunds['by_method'][$m] = bcadd($refunds['by_method'][$m] ?? '0.00', $amount, 2);
            } else {
                $credits['count']++;
                $credits['total'] = bcadd($credits['total'], $amount, 2);
            }
        }
        $refunds['by_method'] = array_map(fn ($m, $a) => ['method' => (string) $m, 'amount' => $a], array_keys($refunds['by_method']), array_values($refunds['by_method']));

        return [
            'refunds' => $refunds,
            'credit_notes' => $credits,
            'net' => bcsub($total, $refunds['total'], 2),
            'date' => $date,
            'currency' => app(HospitalSettings::class)->currencyCode(),
            'totals_by_method' => $methods,
            'total' => $total,
            'payments_count' => $payments->count(),
            'invoices_raised' => Invoice::query()->where('created_at', '>=', $from)->where('created_at', '<', $to)
                ->where('status', '!=', InvoiceStatus::Draft->value)->count(),
            'voided' => Invoice::query()->where('status', InvoiceStatus::Void->value)
                ->where('updated_at', '>=', $from)->where('updated_at', '<', $to)->count(),
            'by_user' => array_map(fn ($name, $t) => ['name' => (string) $name, 'total' => $t], array_keys($users), array_values($users)),
        ];
    }

    private function zone(?Hospital $hospital): string
    {
        return $hospital === null ? (string) config('app.timezone') : $this->zones->zone($hospital);
    }
}
