<?php

namespace App\Support\Dashboard;

use App\Models\User;
use App\Support\HospitalSettings;
use Illuminate\Support\Str;

/**
 * The stat cards at the top of each role's dashboard — one definition for
 * every client.
 *
 * The web draws them (`livewire/dashboard/widgets/<role>/stats.blade.php`)
 * and the API serves them (GET /api/v1/dashboard) from THIS list, so a label,
 * an icon or a link changed here changes in both. They used to be written out
 * in each Blade partial, where a second client could only have copied them.
 *
 * Each card: value (already formatted), label, icon, tone ('' | ok | warn |
 * bad), sub (or null), route (a web route name, or null). Cards a person may
 * not see are left out — the same @can checks the partials made.
 */
final class StatCards
{
    /**
     * @param  array<string,mixed>  $d  the widget's data (DashboardWidgets::data)
     * @return list<array{value:string,label:string,icon:string,tone:string,sub:?string,route:?string}>
     */
    public static function for(string $roleView, array $d, User $user): array
    {
        $money = app(HospitalSettings::class);
        $n = fn (mixed $v) => number_format((int) ($v ?? 0));
        // Allowed AND part of this practice: no bed count for a dental clinic.
        $practice = \App\Support\Practice\Practice::current();
        $can = fn (string $permission) => $user->can($permission) && $practice->allows($permission);

        $cards = match ($roleView) {
            // The five figures a day is read from. It used to open on the size
            // of the patient REGISTER — a number that barely moves and that
            // nobody acts on. A visit is the spine of this system, so "how much
            // work is in the building" leads.
            'admin' => [
                // Who came today and what was done for them.
                $can('visits.view') ? self::card(
                    $n($d['patientsToday'] ?? 0), 'Patients today', 'fa-user-injured', '',
                    $n($d['treatmentsToday'] ?? 0).' '.Str::plural('treatment', (int) ($d['treatmentsToday'] ?? 0)).' done', 'admin.visits.index',
                ) : null,
                $can('visits.view') ? self::card(
                    $n($d['visits']['open'] ?? 0), 'Open visits', 'fa-stethoscope',
                    ($d['visits']['blocked'] ?? 0) > 0 ? 'warn' : '',
                    ($d['visits']['blocked'] ?? 0) > 0
                        ? ($d['visits']['blocked'].' held up by open work')
                        : (($d['visits']['pending'] ?? 0).' not started'),
                    'admin.visits.index',
                ) : null,
                $can('appointments.view') ? self::card(
                    $n($d['appointmentsToday'] ?? 0), 'Appointments today', 'fa-calendar-check', '',
                    ($d['awaitingStart'] ?? 0).' awaiting start · '.($d['appointmentsUpcoming'] ?? 0).' in the next 7 days', 'admin.appointments.queue',
                ) : null,
                $can('ipd.view') ? self::card($n($d['inpatients'] ?? 0), 'Inpatients', 'fa-bed', '', ($d['occupancy']['rate'] ?? 0).'% beds full', 'admin.admissions.board') : null,
                // What came in, by the cashier's own day (CashierDaySummary).
                $can('billing.view') ? self::card(
                    $money->format($d['income']['today'] ?? ($d['revenueToday'] ?? '0')), 'Collected today', 'fa-coins', 'ok',
                    ($d['income']['todayCount'] ?? $d['paymentsToday'] ?? 0).' '.Str::plural('payment', (int) ($d['income']['todayCount'] ?? $d['paymentsToday'] ?? 0)), 'admin.cashier.day',
                ) : null,
                $can('billing.view') ? self::card(
                    $money->format($d['income']['month'] ?? '0'), 'Collected this month', 'fa-sack-dollar', 'ok',
                    null, $user->can('reports.view') ? 'admin.reports.monthly' : 'admin.cashier.day',
                ) : null,
                $can('billing.view') ? self::card(
                    $money->format($d['outstanding']['total'] ?? '0'), 'Outstanding', 'fa-file-invoice-dollar',
                    ($d['outstanding']['count'] ?? 0) ? 'warn' : '',
                    ($d['outstanding']['count'] ?? 0).' unpaid '.Str::plural('invoice', $d['outstanding']['count'] ?? 0), 'admin.invoices.index',
                ) : null,
                $can('pharmacy.view') ? self::card(
                    $n($d['lowStock'] ?? 0), 'Low stock', 'fa-triangle-exclamation', ($d['lowStock'] ?? 0) ? 'warn' : '',
                    'at or below the reorder level', 'admin.stock.alerts',
                ) : null,
                // Products given without billing (docs/orders.md), at cost.
                $can('billing.view') && $can('pharmacy.view') ? self::card(
                    $money->format($d['notBilled']['cost'] ?? '0'), 'Not billed this month', 'fa-box-open', '',
                    $n($d['notBilled']['count'] ?? 0).' product '.Str::plural('line', (int) ($d['notBilled']['count'] ?? 0)).', at cost', 'admin.stock.movements',
                ) : null,
            ],

            'doctor' => [
                $can('appointments.view') ? self::card($n($d['appointmentsToday'] ?? 0), 'My appointments today', 'fa-calendar-check', '', null, 'admin.appointments.index') : null,
                $can('visits.view') ? self::card($n($d['openVisits'] ?? 0), 'My open visits', 'fa-stethoscope', 'warn', null, 'admin.visits.index') : null,
                $can('prescriptions.prescribe') ? self::card($n($d['rxToday'] ?? 0), 'Prescriptions today', 'fa-prescription') : null,
                $can('ipd.view') ? self::card($n($d['inpatients'] ?? 0), 'My inpatients', 'fa-bed', '', null, 'admin.admissions.board') : null,
            ],

            'nurse' => [
                $can('visits.vitals') ? self::card($n($d['notStarted'] ?? 0), 'Awaiting vitals', 'fa-heart-pulse', 'warn', null, 'admin.visits.index') : null,
                $can('prescriptions.administer') ? self::card($n($d['dosesDue'] ?? 0), 'Doses due today', 'fa-pills', '', ($d['dosesMissed'] ?? 0).' missed') : null,
                $can('ipd.view') ? self::card($n($d['inpatients'] ?? 0), 'Inpatients', 'fa-bed', '', null, 'admin.admissions.board') : null,
                $can('treatments.manage') ? self::card($n($d['procedures'] ?? 0), 'Procedures today', 'fa-syringe') : null,
            ],

            'receptionist' => [
                $can('patients.view') ? self::card($n($d['patientsNewToday'] ?? 0), 'New patients today', 'fa-user-plus', '', null, 'admin.patients.index') : null,
                $can('appointments.view') ? self::card($n($d['appointmentsToday'] ?? 0), 'Appointments today', 'fa-calendar-check', '', null, 'admin.appointments.queue') : null,
                $can('appointments.view') ? self::card($n($d['awaitingStart'] ?? 0), 'Checked-in waiting', 'fa-users-line', 'warn', null, 'admin.appointments.queue') : null,
                $can('billing.view') ? self::card($money->format($d['revenueToday'] ?? '0'), 'Payments today', 'fa-coins', 'ok', ($d['paymentsToday'] ?? 0).' received', 'admin.invoices.index') : null,
            ],

            'pharmacist' => [
                self::card($n($d['lowStock'] ?? 0), 'Low stock items', 'fa-triangle-exclamation', ($d['lowStock'] ?? 0) ? 'bad' : '', null, 'admin.stock.alerts'),
                self::card($n($d['expiring'] ?? 0), 'Expiring ≤90 days', 'fa-hourglass-half', ($d['expiring'] ?? 0) ? 'warn' : '', null, 'admin.stock.index'),
                self::card($money->format($d['valuation']['total_value'] ?? '0'), 'Stock value', 'fa-boxes-stacked', '', $n($d['valuation']['items'] ?? 0).' active items', 'admin.stock.index'),
                self::card($n($d['dispensations'] ?? 0), 'Dispensations today', 'fa-prescription-bottle-medical', 'ok'),
            ],

            'lab' => [
                self::card($n($d['pending'] ?? 0), 'Pending orders', 'fa-flask', 'warn', null, 'admin.lab-orders.index'),
                self::card($n($d['stages']['processing'] ?? 0), 'In processing', 'fa-vials', '', null, 'admin.lab-orders.index'),
                self::card($n($d['stages']['collected'] ?? 0), 'Awaiting processing', 'fa-vial-circle-check'),
                self::card($n($d['completedToday'] ?? 0), 'Completed today', 'fa-clipboard-check', 'ok'),
            ],

            'radiology' => [
                self::card($n($d['awaitingReport'] ?? 0), 'Awaiting report', 'fa-file-waveform', 'warn', null, 'admin.radiology-orders.index'),
                self::card($n($d['pending'] ?? 0), 'Pending orders', 'fa-x-ray', '', null, 'admin.radiology-orders.index'),
                self::card($n($d['reportedToday'] ?? 0), 'Reported today', 'fa-clipboard-check', 'ok'),
            ],

            'accountant' => [
                self::card($money->format($d['revenueToday'] ?? '0'), 'Revenue today', 'fa-coins', 'ok', ($d['paymentsToday'] ?? 0).' payments'),
                self::card($money->format($d['revenueMonth'] ?? '0'), 'Revenue this month', 'fa-sack-dollar', 'ok'),
                self::card(
                    $money->format($d['outstanding']['total'] ?? '0'), 'Outstanding', 'fa-file-invoice-dollar',
                    ($d['outstanding']['count'] ?? 0) ? 'warn' : '', ($d['outstanding']['count'] ?? 0).' unpaid', 'admin.invoices.index',
                ),
                $can('insurance.view') ? self::card($n($d['claims']['count'] ?? 0), 'Pending claims', 'fa-shield-heart', '', $money->format($d['claims']['total'] ?? '0'), 'admin.insurance-claims.index') : null,
            ],

            'records' => [
                self::card($n($d['total'] ?? 0), 'Total patients', 'fa-user-injured', '', null, 'admin.patients.index'),
                self::card($n($d['newToday'] ?? 0), 'New today', 'fa-user-plus', 'ok'),
                self::card($n($d['newWeek'] ?? 0), 'New this week', 'fa-calendar-week'),
                self::card($n($d['byStatus']['active'] ?? 0), 'Active patients', 'fa-user-check'),
            ],

            'super' => [
                self::card($n($d['hospitals'] ?? 0), 'Hospitals', 'fa-hospital', '', null, 'super.hospitals.index'),
                self::card($n($d['subs']['active'] ?? 0), 'Active subscriptions', 'fa-circle-check', 'ok', null, 'super.subscriptions.index'),
                self::card($n($d['trialsExpiring'] ?? 0), 'Trials expiring ≤7d', 'fa-hourglass-half', ($d['trialsExpiring'] ?? 0) ? 'warn' : '', null, 'super.subscriptions.index'),
                self::card($money->format($d['mrr'] ?? '0'), 'Est. MRR', 'fa-arrow-trend-up', 'ok'),
            ],

            // Permission-driven: whatever the role is allowed to see.
            default => [
                $can('patients.view') ? self::card($n($d['patientsTotal'] ?? 0), 'Patients', 'fa-user-injured', '', ($d['patientsNewToday'] ?? 0).' new today', 'admin.patients.index') : null,
                $can('appointments.view') ? self::card($n($d['appointmentsToday'] ?? 0), 'Appointments today', 'fa-calendar-check', '', null, 'admin.appointments.index') : null,
                $can('visits.view') ? self::card($n($d['visitsToday'] ?? 0), 'Visits today', 'fa-stethoscope', '', null, 'admin.visits.index') : null,
                $can('ipd.view') ? self::card($n($d['inpatients'] ?? 0), 'Inpatients', 'fa-bed', '', null, 'admin.admissions.board') : null,
                $can('billing.view') ? self::card($money->format($d['revenueToday'] ?? '0'), 'Revenue today', 'fa-coins', 'ok') : null,
                $can('pharmacy.view') ? self::card($n($d['lowStock'] ?? 0), 'Low stock', 'fa-triangle-exclamation', ($d['lowStock'] ?? 0) ? 'warn' : '', null, 'admin.stock.alerts') : null,
                $can('lab.view') ? self::card($n($d['labPending'] ?? 0), 'Lab pending', 'fa-flask', '', null, 'admin.lab-orders.index') : null,
                $can('radiology.view') ? self::card($n($d['radPending'] ?? 0), 'Radiology pending', 'fa-x-ray', '', null, 'admin.radiology-orders.index') : null,
            ],
        };

        return array_values(array_filter($cards));
    }

    /** @return array{value:string,label:string,icon:string,tone:string,sub:?string,route:?string} */
    private static function card(string $value, string $label, string $icon, string $tone = '', ?string $sub = null, ?string $route = null): array
    {
        return compact('value', 'label', 'icon', 'tone', 'sub', 'route');
    }
}
