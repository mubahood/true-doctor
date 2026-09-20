<?php

namespace App\Support\Dashboard;

use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Support\Facades\Cache;

/**
 * The dashboard's widget catalogue and its short-TTL read cache
 * (docs/PJAX_LIVEWIRE_IMPROVEMENT_PLAN.md D10, L3).
 *
 * `Dashboard\Section` never touches DashboardService directly: it asks for a
 * named widget's data and always gets it through `Cache::remember`, keyed by
 * hospital + role view + user + widget, so the ~20 aggregate queries a role
 * dashboard used to run on every load now run at most once every 45 seconds
 * per viewer — while the sections poll every 60 s.
 *
 * WIDGETS is also the security whitelist: a widget name arriving from the wire
 * is only ever rendered when it belongs to the viewer's own role view and the
 * viewer holds the permission that gated it in the old Blade partial.
 */
class DashboardWidgets
{
    /** Seconds a widget payload stays cached (plan: 30–60 s). */
    public const TTL = 45;

    /**
     * Role view => the widgets it may render, in display order.
     * Mirrors the old `admin/dashboard/roles/*.blade.php` sections exactly.
     *
     * @var array<string,list<string>>
     */
    public const WIDGETS = [
        // Ordered the way the day is read: what is in the building, then the
        // money it turns into, then the work holding it up, then the rest.
        'admin' => [
            'admin.stats', 'admin.visit-flow', 'admin.money-trend', 'admin.occupancy',
            'admin.work-queue', 'admin.revenue-by-method', 'admin.cards',
            'admin.appointments-by-status', 'admin.low-stock', 'admin.side-stats',
        ],
        'doctor' => ['doctor.stats', 'doctor.visits', 'doctor.diary'],
        'nurse' => ['nurse.stats', 'nurse.not-started', 'nurse.doses'],
        'receptionist' => ['receptionist.stats', 'receptionist.diary', 'receptionist.awaiting-payment'],
        'pharmacist' => ['pharmacist.stats', 'pharmacist.low-stock', 'pharmacist.expiring', 'pharmacist.top-dispensed'],
        'lab' => ['lab.stats', 'lab.worklist', 'lab.stages'],
        'radiology' => ['radiology.stats', 'radiology.queue'],
        'accountant' => ['accountant.stats', 'accountant.revenue-trend', 'accountant.breakdowns', 'accountant.outstanding', 'accountant.claims'],
        'records' => ['records.stats', 'records.by-status', 'records.recent'],
        'super' => ['super.stats', 'super.charts', 'super.per-plan'],
        'fallback' => ['fallback.stats'],
    ];

    /**
     * Permission that gated the widget in the old role partial (null = the
     * whole widget was ungated there, its inner cards carry their own @can).
     *
     * @var array<string,string|null>
     */
    private const PERMISSIONS = [
        'admin.stats' => null,
        'admin.visit-flow' => 'visits.view',
        'admin.money-trend' => 'finance.view',
        'admin.work-queue' => 'visits.view',
        'admin.cards' => 'patients.card.manage',
        'admin.revenue-trend' => 'finance.view',
        'admin.occupancy' => 'ipd.view',
        'admin.appointments-by-status' => 'appointments.view',
        'admin.revenue-by-method' => 'billing.view',
        'admin.low-stock' => 'pharmacy.view',
        'admin.side-stats' => null,
        'doctor.stats' => null,
        'doctor.visits' => 'visits.view',
        'doctor.diary' => 'appointments.view',
        'nurse.stats' => null,
        'nurse.not-started' => 'visits.vitals',
        'nurse.doses' => 'prescriptions.administer',
        'receptionist.stats' => null,
        'receptionist.diary' => 'appointments.view',
        'receptionist.awaiting-payment' => 'billing.view',
        'pharmacist.stats' => null,
        'pharmacist.low-stock' => null,
        'pharmacist.expiring' => null,
        'pharmacist.top-dispensed' => null,
        'lab.stats' => null,
        'lab.worklist' => null,
        'lab.stages' => null,
        'radiology.stats' => null,
        'radiology.queue' => null,
        'accountant.stats' => null,
        'accountant.revenue-trend' => null,
        'accountant.breakdowns' => null,
        'accountant.outstanding' => null,
        'accountant.claims' => null,
        'records.stats' => null,
        'records.by-status' => null,
        'records.recent' => null,
        'super.stats' => null,
        'super.charts' => null,
        'super.per-plan' => null,
        'fallback.stats' => null,
    ];

    public function __construct(
        private readonly DashboardService $svc,
        private readonly CurrentHospital $current,
    ) {}

    /** May this user render this widget inside this role view? */
    public static function allows(string $roleView, string $widget, ?User $user): bool
    {
        if ($user === null || ! in_array($widget, self::WIDGETS[$roleView] ?? [], true)) {
            return false;
        }

        $permission = self::PERMISSIONS[$widget] ?? null;

        return $permission === null || $user->can($permission);
    }

    /**
     * The widget's payload — always from cache (45 s), never a raw service call.
     *
     * @return array<string,mixed>
     */
    public function data(string $widget, string $roleView, User $user): array
    {
        $key = sprintf('dash:%s:%s:%s:%s', $this->current->id() ?? 'none', $roleView, $user->id, $widget);

        return Cache::remember($key, self::TTL, fn () => $this->build($widget, $user));
    }

    /** @return array<string,mixed> */
    private function build(string $widget, User $user): array
    {
        $s = $this->svc;

        return match ($widget) {
            // ── Hospital admin ──────────────────────────────────
            'admin.stats' => array_merge(
                $user->can('visits.view') ? ['visits' => $s->visitsOpen()] : [],
                $user->can('appointments.view') ? ['appointmentsToday' => $s->appointmentsToday(), 'awaitingStart' => $s->appointmentsAwaitingStart()] : [],
                $user->can('ipd.view') ? ['inpatients' => $s->currentInpatients(), 'occupancy' => $s->occupancy()] : [],
                $user->can('billing.view') ? ['revenueToday' => $s->revenueToday(), 'paymentsToday' => $s->paymentsCountToday(), 'outstanding' => $s->outstandingInvoices()] : [],
            ),
            'admin.visit-flow' => ['visits' => $s->visitsOpen()],
            'admin.work-queue' => ['orders' => $s->openOrders()],
            'admin.cards' => ['cards' => $s->cardsSnapshot()],
            'admin.money-trend' => ['trend' => $s->billedAndCollected(14)],
            'admin.revenue-trend', 'accountant.revenue-trend' => ['trend' => $s->revenueTrend(14)],
            'admin.occupancy' => ['occupancy' => $s->occupancy()],
            'admin.appointments-by-status' => ['byStatus' => $s->appointmentsByStatusToday()],
            'admin.revenue-by-method' => ['byMethod' => $s->revenueByMethodThisMonth()],
            'admin.low-stock' => ['count' => $s->lowStockCount(), 'rows' => $s->lowStockList(6)],
            'admin.side-stats' => array_merge(
                $user->can('patients.view') ? ['patientsTotal' => $s->patientsTotal(), 'patientsNewToday' => $s->patientsNewToday()] : [],
                $user->can('finance.view') ? ['claims' => $s->pendingClaims(), 'staff' => $s->staffCount()] : [],
                $user->can('lab.view') ? ['labPending' => $s->labPendingCount()] : [],
                $user->can('radiology.view') ? ['radPending' => $s->radPendingCount() + $s->radAwaitingReport()] : [],
            ),

            // ── Doctor ──────────────────────────────────────────
            'doctor.stats' => array_merge(
                $user->can('appointments.view') ? ['appointmentsToday' => $s->appointmentsToday($user->id)] : [],
                $user->can('visits.view') ? ['openVisits' => $s->openVisitsForDoctor($user->id)] : [],
                $user->can('prescriptions.prescribe') ? ['rxToday' => $s->rxTodayForDoctor($user->id)] : [],
                $user->can('ipd.view') ? ['inpatients' => $s->inpatientsForDoctor($user->id)] : [],
            ),
            'doctor.visits' => ['rows' => $s->openVisitList($user->id, 8)],
            'doctor.diary' => ['rows' => $s->todaysDiary($user->id, 8)],

            // ── Nurse ───────────────────────────────────────────
            'nurse.stats' => array_merge(
                $user->can('visits.vitals') ? ['notStarted' => $s->notStarted()] : [],
                $user->can('prescriptions.administer') ? ['dosesDue' => $s->dosesDueToday(), 'dosesMissed' => $s->missedDosesToday()] : [],
                $user->can('ipd.view') ? ['inpatients' => $s->currentInpatients()] : [],
                $user->can('treatments.manage') ? ['procedures' => $s->proceduresToday()] : [],
            ),
            'nurse.not-started' => ['rows' => $s->notStartedQueue(8)],
            'nurse.doses' => ['bySlot' => $s->dosesDueBySlot()],

            // ── Receptionist ────────────────────────────────────
            'receptionist.stats' => array_merge(
                $user->can('patients.view') ? ['patientsNewToday' => $s->patientsNewToday()] : [],
                $user->can('appointments.view') ? ['appointmentsToday' => $s->appointmentsToday(), 'awaitingStart' => $s->appointmentsAwaitingStart()] : [],
                $user->can('billing.view') ? ['revenueToday' => $s->revenueToday(), 'paymentsToday' => $s->paymentsCountToday()] : [],
            ),
            'receptionist.diary' => ['rows' => $s->todaysDiary(null, 10)],
            'receptionist.awaiting-payment' => ['count' => $s->outstandingInvoices()['count'], 'rows' => $s->outstandingInvoiceList(8)],

            // ── Pharmacist ──────────────────────────────────────
            'pharmacist.stats' => [
                'lowStock' => $s->lowStockCount(), 'expiring' => $s->expiringCount(),
                'valuation' => $s->stockValuation(), 'dispensations' => $s->dispensationsToday(),
            ],
            'pharmacist.low-stock' => ['count' => $s->lowStockCount(), 'rows' => $s->lowStockList(8)],
            'pharmacist.expiring' => ['count' => $s->expiringCount(), 'rows' => $s->expiringList(90, 8)],
            'pharmacist.top-dispensed' => ['top' => $s->topDrugsToday(6)],

            // ── Lab ─────────────────────────────────────────────
            'lab.stats' => ['pending' => $s->labPendingCount(), 'stages' => $s->labByStage(), 'completedToday' => $s->labCompletedToday()],
            'lab.worklist' => ['count' => $s->labPendingCount(), 'rows' => $s->labWorklist(9)],
            'lab.stages' => ['stages' => $s->labByStage(), 'abnormal' => $s->labAbnormalRecent(6)],

            // ── Radiology ───────────────────────────────────────
            'radiology.stats' => ['awaitingReport' => $s->radAwaitingReport(), 'pending' => $s->radPendingCount(), 'reportedToday' => $s->radReportedToday()],
            'radiology.queue' => ['count' => $s->radAwaitingReport(), 'rows' => $s->radReportQueue(10)],

            // ── Accountant ──────────────────────────────────────
            'accountant.stats' => [
                'revenueToday' => $s->revenueToday(), 'paymentsToday' => $s->paymentsCountToday(),
                'revenueMonth' => $s->revenueThisMonth(), 'outstanding' => $s->outstandingInvoices(),
                'claims' => $s->pendingClaims(),
            ],
            'accountant.breakdowns' => ['byMethod' => $s->revenueByMethodThisMonth(), 'byStatus' => $s->invoicesByStatus()],
            'accountant.outstanding' => ['count' => $s->outstandingInvoices()['count'], 'rows' => $s->outstandingInvoiceList(8)],
            'accountant.claims' => ['claims' => $s->pendingClaims(), 'rows' => $s->pendingClaimList(6)],

            // ── Records officer ─────────────────────────────────
            'records.stats' => ['total' => $s->patientsTotal(), 'newToday' => $s->patientsNewToday(), 'newWeek' => $s->patientsNewThisWeek(), 'byStatus' => $s->patientsByStatus()],
            'records.by-status' => ['byStatus' => $s->patientsByStatus()],
            'records.recent' => ['rows' => $s->recentPatients(8)],

            // ── SaaS operator ───────────────────────────────────
            'super.stats' => [
                'hospitals' => $s->saasHospitalsTotal(), 'subs' => $s->saasSubscriptionsByStatus(),
                'trialsExpiring' => $s->saasTrialsExpiringSoon(7), 'mrr' => $s->saasMrrEstimate(),
            ],
            'super.charts' => ['byStatus' => $s->saasHospitalsByStatus(), 'subs' => $s->saasSubscriptionsByStatus()],
            'super.per-plan' => ['perPlan' => $s->saasSubscribersPerPlan()],

            // ── Permission-driven fallback ──────────────────────
            'fallback.stats' => array_merge(
                $user->can('patients.view') ? ['patientsTotal' => $s->patientsTotal(), 'patientsNewToday' => $s->patientsNewToday()] : [],
                $user->can('appointments.view') ? ['appointmentsToday' => $s->appointmentsToday()] : [],
                $user->can('visits.view') ? ['visitsToday' => $s->visitsToday()] : [],
                $user->can('ipd.view') ? ['inpatients' => $s->currentInpatients()] : [],
                $user->can('billing.view') ? ['revenueToday' => $s->revenueToday()] : [],
                $user->can('pharmacy.view') ? ['lowStock' => $s->lowStockCount()] : [],
                $user->can('lab.view') ? ['labPending' => $s->labPendingCount()] : [],
                $user->can('radiology.view') ? ['radPending' => $s->radPendingCount() + $s->radAwaitingReport()] : [],
            ),

            default => [],
        };
    }
}
