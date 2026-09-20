<?php

namespace App\Support\Dashboard;

use App\Enums\AdmissionStatus;
use App\Enums\CardStatus;
use App\Enums\ClaimStatus;
use App\Enums\DoseRecordStatus;
use App\Enums\InvoiceStatus;
use App\Enums\LabOrderStatus;
use App\Enums\OrderStatus;
use App\Enums\PatientStatus;
use App\Enums\RadiologyOrderStatus;
use App\Enums\VisitOutcome;
use App\Enums\VisitStage;
use App\Enums\VisitStatus;
use App\Models\Admission;
use App\Models\Appointment;
use App\Models\Dispensation;
use App\Models\DispensationItem;
use App\Models\DoseItemRecord;
use App\Models\InsuranceClaim;
use App\Models\InsuranceProvider;
use App\Models\Invoice;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\Order;
use App\Models\Patient;
use App\Models\PatientCard;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\RadiologyOrder;
use App\Models\StockItem;
use App\Models\TreatmentRecord;
use App\Models\User;
use App\Models\Visit;
use App\Services\ReportService;
use App\Support\CurrentHospital;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only metric provider for the role-based dashboards (DASHBOARD_PLAN.md §5).
 * Every clinical/billing/stock query is tenant-scoped by the global HospitalScope;
 * User is scoped by hand (no global scope). Money is summed in bcmath (decimal
 * strings) — never float — matching ReportService. Methods return raw data; the
 * views format money via HospitalSettings::format().
 */
class DashboardService
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly CurrentHospital $current,
    ) {}

    // ── Time helpers ────────────────────────────────────────────
    private function todayRange(): array
    {
        return [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()];
    }

    private function weekRange(): array
    {
        return [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()];
    }

    private function monthRange(): array
    {
        return [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()];
    }

    // ── Staff / org ─────────────────────────────────────────────
    public function staffCount(): int
    {
        return User::where('hospital_id', $this->current->id())->count();
    }

    /** @return array<string,int> role => count */
    public function staffByRole(): array
    {
        return User::where('hospital_id', $this->current->id())
            ->selectRaw('role, count(*) as c')->groupBy('role')
            ->pluck('c', 'role')->map(fn ($c) => (int) $c)->toArray();
    }

    // ── Patients ────────────────────────────────────────────────
    public function patientsTotal(): int
    {
        return Patient::count();
    }

    public function patientsNewToday(): int
    {
        return Patient::whereBetween('created_at', $this->todayRange())->count();
    }

    public function patientsNewThisWeek(): int
    {
        return Patient::whereBetween('created_at', $this->weekRange())->count();
    }

    /** @return array<string,int> */
    public function patientsByStatus(): array
    {
        $counts = Patient::selectRaw('status, count(*) as c')->groupBy('status')
            ->pluck('c', 'status')->map(fn ($c) => (int) $c)->toArray();
        $out = [];
        foreach (PatientStatus::cases() as $s) {
            $out[$s->value] = $counts[$s->value] ?? 0;
        }

        return $out;
    }

    public function cardFloat(): string
    {
        $sum = '0.00';
        foreach (PatientCard::where('status', 'active')->pluck('balance') as $b) {
            $sum = bcadd($sum, (string) $b, 2);
        }

        return $sum;
    }

    /** Recently registered patients for the records dashboard. */
    public function recentPatients(int $limit = 8): Collection
    {
        return Patient::latest('created_at')->limit($limit)
            ->get(['id', 'uuid', 'patient_no', 'first_name', 'last_name', 'sex', 'status', 'created_at']);
    }

    // ── Appointments ────────────────────────────────────────────
    public function appointmentsToday(?int $doctorId = null): int
    {
        return Appointment::forDate(Carbon::now()->toDateString())
            ->when($doctorId, fn ($q) => $q->where('doctor_user_id', $doctorId))
            ->count();
    }

    /** @return array<string,int> */
    public function appointmentsByStatusToday(): array
    {
        return Appointment::forDate(Carbon::now()->toDateString())
            ->selectRaw('status, count(*) as c')->groupBy('status')
            ->pluck('c', 'status')->map(fn ($c) => (int) $c)->toArray();
    }

    public function appointmentsAwaitingStart(): int
    {
        return Appointment::forDate(Carbon::now()->toDateString())
            ->where('status', 'checked_in')->count();
    }

    /** Today's diary rows (optionally for one doctor) for the queue widget. */
    public function todaysDiary(?int $doctorId = null, int $limit = 8): Collection
    {
        return Appointment::forDate(Carbon::now()->toDateString())
            ->when($doctorId, fn ($q) => $q->where('doctor_user_id', $doctorId))
            ->with(['patient:id,uuid,first_name,last_name', 'doctor:id,name'])
            ->orderBy('scheduled_at')->limit($limit)->get();
    }

    // ── Visits / visits ──────────────────────────────
    /** Patients this doctor is currently dealing with — care, not money. */
    public function openVisitsForDoctor(int $doctorId): int
    {
        return $this->inCare()->where('doctor_user_id', $doctorId)->count();
    }

    public function openVisitList(int $doctorId, int $limit = 8): Collection
    {
        return $this->inCare()->where('doctor_user_id', $doctorId)
            ->with('patient:id,uuid,first_name,last_name')
            ->latest('created_at')->limit($limit)->get();
    }

    /**
     * Opened and not yet started.
     *
     * This used to count visits at `triage`, and kept the word in its name long
     * after the stage was gone. There is no triage stage — where a patient
     * physically is, is answered by their orders (docs/visits.md) — and the
     * queue a nurse actually wants is the one nobody has begun.
     */
    public function notStarted(): int
    {
        return Visit::where('status', VisitStatus::Pending->value)->count();
    }

    /** The same queue, as rows. */
    public function notStartedQueue(int $limit = 8): Collection
    {
        return Visit::where('status', VisitStatus::Pending->value)
            ->with('patient:id,uuid,first_name,last_name')
            ->latest('created_at')->limit($limit)->get();
    }

    /** Ongoing, and still in care rather than in the money stages. */
    private function inCare(): \Illuminate\Database\Eloquent\Builder
    {
        return Visit::where('status', VisitStatus::Ongoing->value)
            ->where('stage', VisitStage::Ongoing->value);
    }

    /**
     * The funnel, by the stage each visit is at.
     *
     * Keyed by stage rather than status now: Pending · Ongoing · Billing ·
     * Payment · Completed · Cancelled is what a reader means by "where are
     * they all", and status alone has only three values.
     *
     * @return array<string,int>
     */
    public function visitPipeline(): array
    {
        // Raw rows, not models: this is a count per combination, and hydrating
        // a Visit for each one only to read three strings off it is waste.
        $rows = Visit::query()->toBase()
            ->selectRaw('status, stage, outcome, count(*) as c')
            ->groupBy('status', 'stage', 'outcome')->get();

        $out = [
            VisitStatus::Pending->value => 0,
            VisitStage::Ongoing->value => 0,
            VisitStage::Billing->value => 0,
            VisitStage::Payment->value => 0,
            VisitOutcome::Closed->value => 0,
            VisitOutcome::Cancelled->value => 0,
        ];

        foreach ($rows as $row) {
            $key = match ($row->status) {
                VisitStatus::Pending->value => VisitStatus::Pending->value,
                VisitStatus::Completed->value => (string) ($row->outcome ?: VisitOutcome::Closed->value),
                default => (string) $row->stage,
            };
            $out[$key] = ($out[$key] ?? 0) + (int) $row->c;
        }

        return $out;
    }

    public function visitsToday(): int
    {
        return Visit::whereBetween('created_at', $this->todayRange())->count();
    }

    /**
     * What is in the building right now.
     *
     * The dashboard used to open on the size of the patient REGISTER, which is
     * a number that barely moves and that nobody acts on. A visit is the spine
     * of this system (docs/visits.md) — every order, charge and admission hangs
     * off one — so this is the figure the day is actually read from.
     *
     * `blocked` is the one that costs money: visits whose work is finished
     * except for an order nobody has closed, which is what keeps a patient
     * sitting between the consulting room and the cashier.
     *
     * @return array{open:int,pending:int,inCare:int,billing:int,payment:int,blocked:int}
     */
    public function visitsOpen(): array
    {
        $rows = Visit::query()->toBase()
            ->selectRaw('status, stage, count(*) as c')
            ->whereIn('status', [VisitStatus::Pending->value, VisitStatus::Ongoing->value])
            ->groupBy('status', 'stage')
            ->get();

        $out = ['open' => 0, 'pending' => 0, 'inCare' => 0, 'billing' => 0, 'payment' => 0, 'blocked' => 0];

        foreach ($rows as $row) {
            $count = (int) $row->c;
            $out['open'] += $count;

            if ($row->status === VisitStatus::Pending->value) {
                $out['pending'] += $count;

                continue;
            }

            $key = match ($row->stage) {
                VisitStage::Billing->value => 'billing',
                VisitStage::Payment->value => 'payment',
                default => 'inCare',
            };
            $out[$key] += $count;
        }

        $out['blocked'] = Visit::where('status', VisitStatus::Ongoing->value)
            ->where('stage', VisitStage::Ongoing->value)
            ->whereHas('orders', fn ($q) => $q->whereIn(
                'status', [OrderStatus::Pending->value, OrderStatus::InProgress->value],
            ))
            ->count();

        return $out;
    }

    // ── Orders — the work itself ────────────────────────────────

    /**
     * Everything still to be done, by the kind of work it is.
     *
     * An order is how work reaches the person who does it, and until now the
     * dashboard could only see the two kinds that had their own module (lab and
     * radiology). A dispensing nobody has picked up, a procedure nobody has
     * recorded and a consultation nobody has closed were all invisible — and
     * every one of them holds a visit out of billing.
     *
     * @return array{total:int,byType:array<string,int>,oldestDays:?int}
     */
    public function openOrders(): array
    {
        $open = [OrderStatus::Pending->value, OrderStatus::InProgress->value];

        $rows = Order::query()->toBase()
            ->selectRaw('type, count(*) as c')
            ->whereIn('status', $open)
            ->whereNull('deleted_at')
            ->groupBy('type')
            ->get();

        $byType = [];
        $total = 0;

        foreach ($rows as $row) {
            $byType[(string) $row->type] = (int) $row->c;
            $total += (int) $row->c;
        }

        arsort($byType);

        // How long the oldest one has been waiting. A queue of four that has
        // been four for a week is a different problem from a queue of four
        // raised this morning.
        $oldest = Order::whereIn('status', $open)->min('created_at');

        return [
            'total' => $total,
            'byType' => $byType,
            'oldestDays' => $oldest === null ? null : (int) Carbon::parse($oldest)->startOfDay()->diffInDays(Carbon::now()->startOfDay()),
        ];
    }

    /** The oldest open orders, for the worklist widget. */
    public function openOrderList(int $limit = 8): Collection
    {
        return Order::whereIn('status', [OrderStatus::Pending->value, OrderStatus::InProgress->value])
            ->with(['patient:id,uuid,first_name,last_name', 'visit:id,visit_no'])
            ->oldest('created_at')
            ->limit($limit)
            ->get();
    }

    // ── Cards and the insurers behind them ──────────────────────

    /**
     * The card desk in four figures (docs/cards.md).
     *
     * `held` is money the hospital is holding on behalf of patients and will
     * have to honour; `owed` is money its members have already spent and not
     * repaid. They are opposite sides of the same instrument and a dashboard
     * that showed only their sum — which `cardFloat()` did — said neither.
     *
     * @return array{cards:int,held:string,owed:string,inDebt:int,float:string,insurers:int}
     */
    public function cardsSnapshot(): array
    {
        $held = '0.00';
        $owed = '0.00';
        $inDebt = 0;
        $cards = 0;

        foreach (PatientCard::where('status', CardStatus::Active->value)->pluck('balance') as $balance) {
            $cards++;
            $balance = (string) $balance;

            if (bccomp($balance, '0', 2) < 0) {
                $inDebt++;
                $owed = bcsub($owed, $balance, 2);

                continue;
            }

            $held = bcadd($held, $balance, 2);
        }

        $float = '0.00';
        $insurers = 0;

        foreach (InsuranceProvider::where('is_active', true)->pluck('float_balance') as $balance) {
            $insurers++;
            $float = bcadd($float, (string) $balance, 2);
        }

        return [
            'cards' => $cards,
            'held' => $held,
            'owed' => $owed,
            'inDebt' => $inDebt,
            'float' => $float,
            'insurers' => $insurers,
        ];
    }

    public function awaitingPayment(): int
    {
        return Visit::where('status', VisitStatus::Ongoing->value)
            ->where('stage', VisitStage::Payment->value)->count();
    }

    // ── Prescriptions / dosing / treatments ─────────────────────
    public function rxTodayForDoctor(int $doctorId): int
    {
        return Prescription::where('prescribed_by', $doctorId)
            ->whereBetween('created_at', $this->todayRange())->count();
    }

    public function dosesDueToday(): int
    {
        return DoseItemRecord::whereDate('scheduled_date', Carbon::now()->toDateString())
            ->whereHas('doseItem.prescription')
            ->where('status', DoseRecordStatus::Pending->value)->count();
    }

    /** @return array<string,int> slot => pending count */
    public function dosesDueBySlot(): array
    {
        return DoseItemRecord::whereDate('scheduled_date', Carbon::now()->toDateString())
            ->whereHas('doseItem.prescription')
            ->where('status', DoseRecordStatus::Pending->value)
            ->selectRaw('slot, count(*) as c')->groupBy('slot')
            ->pluck('c', 'slot')->map(fn ($c) => (int) $c)->toArray();
    }

    public function missedDosesToday(): int
    {
        return DoseItemRecord::whereDate('scheduled_date', Carbon::now()->toDateString())
            ->whereHas('doseItem.prescription')
            ->where('status', DoseRecordStatus::Missed->value)->count();
    }

    public function proceduresToday(?int $performedBy = null): int
    {
        return TreatmentRecord::whereBetween('performed_at', $this->todayRange())
            ->when($performedBy, fn ($q) => $q->where('performed_by', $performedBy))
            ->count();
    }

    // ── Billing / finance ───────────────────────────────────────
    public function revenueToday(): string
    {
        return $this->sumPayments($this->todayRange());
    }

    public function revenueThisMonth(): string
    {
        return $this->sumPayments($this->monthRange());
    }

    private function sumPayments(array $range): string
    {
        $sum = '0.00';
        foreach (Payment::whereBetween('created_at', $range)->pluck('amount') as $a) {
            $sum = bcadd($sum, (string) $a, 2);
        }

        return $sum;
    }

    public function paymentsCountToday(): int
    {
        return Payment::whereBetween('created_at', $this->todayRange())->count();
    }

    /** @return array<string,string> method => total */
    public function revenueByMethodThisMonth(): array
    {
        [$from, $to] = $this->monthRange();

        return $this->reports->revenue($from, $to)['by_method'];
    }

    /** @return array<string,string> date(Y-m-d) => total, last N days incl today */
    public function revenueTrend(int $days = 14): array
    {
        $from = Carbon::now()->subDays($days - 1)->startOfDay();
        $byDay = $this->reports->revenue($from, Carbon::now())['by_day'];
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = Carbon::now()->subDays($i)->toDateString();
            $out[$d] = $byDay[$d] ?? '0.00';
        }

        return $out;
    }

    /** @return array{count:int,total:string} */
    /**
     * What was billed against what was collected, day by day.
     *
     * One line of payments answers "did money come in". It cannot answer the
     * question a hospital administrator actually has, which is whether the
     * money coming in keeps up with the work going out — and the gap between
     * the two lines IS the outstanding balance, accruing in front of them.
     *
     * Billed is dated by when the invoice was ISSUED, not when the work was
     * done: that is the day the hospital asked to be paid.
     *
     * @return array{billed:array<string,string>,collected:array<string,string>,billedTotal:string,collectedTotal:string,days:int}
     */
    public function billedAndCollected(int $days = 14): array
    {
        $from = Carbon::now()->subDays($days - 1)->startOfDay();
        $to = Carbon::now()->endOfDay();

        // Raw rows, not models: this is two columns summed into buckets, and
        // hydrating an Invoice for each one only to read a date and a figure
        // off it is waste. `toBase()` keeps the tenant and soft-delete scopes.
        $billed = $this->sumByDay(
            Invoice::where('status', '!=', InvoiceStatus::Void->value)
                ->whereBetween('issued_at', [$from, $to])
                ->toBase()
                ->get(['issued_at as at', 'total as amount']),
        );

        $collected = $this->sumByDay(
            Payment::whereBetween('created_at', [$from, $to])
                ->toBase()
                ->get(['created_at as at', 'amount']),
        );

        $billedSeries = [];
        $collectedSeries = [];
        $billedTotal = '0.00';
        $collectedTotal = '0.00';

        for ($i = $days - 1; $i >= 0; $i--) {
            $day = Carbon::now()->subDays($i)->toDateString();

            $billedSeries[$day] = $billed[$day] ?? '0.00';
            $collectedSeries[$day] = $collected[$day] ?? '0.00';

            $billedTotal = bcadd($billedTotal, $billedSeries[$day], 2);
            $collectedTotal = bcadd($collectedTotal, $collectedSeries[$day], 2);
        }

        return [
            'billed' => $billedSeries,
            'collected' => $collectedSeries,
            'billedTotal' => $billedTotal,
            'collectedTotal' => $collectedTotal,
            'days' => $days,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int,\stdClass>  $rows  each with `at` and `amount`
     * @return array<string,string>
     */
    private function sumByDay(Collection $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            if ($row->at === null) {
                continue;
            }

            $day = Carbon::parse($row->at)->toDateString();
            $out[$day] = bcadd($out[$day] ?? '0.00', (string) $row->amount, 2);
        }

        return $out;
    }

    public function outstandingInvoices(): array
    {
        $rows = Invoice::whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->get(['balance']);
        $total = '0.00';
        foreach ($rows as $r) {
            $total = bcadd($total, (string) $r->balance, 2);
        }

        return ['count' => $rows->count(), 'total' => $total];
    }

    public function outstandingInvoiceList(int $limit = 8): Collection
    {
        return Invoice::whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->where('balance', '>', 0)
            ->with('patient:id,uuid,first_name,last_name')
            ->orderByDesc('balance')->limit($limit)->get();
    }

    /** @return array<string,int> */
    public function invoicesByStatus(): array
    {
        $counts = Invoice::selectRaw('status, count(*) as c')->groupBy('status')
            ->pluck('c', 'status')->map(fn ($c) => (int) $c)->toArray();
        $out = [];
        foreach (InvoiceStatus::cases() as $s) {
            $out[$s->value] = $counts[$s->value] ?? 0;
        }

        return $out;
    }

    /** @return array{count:int,total:string} */
    public function pendingClaims(): array
    {
        $rows = InsuranceClaim::whereIn('status', [ClaimStatus::Submitted->value, ClaimStatus::Approved->value])
            ->get(['amount']);
        $total = '0.00';
        foreach ($rows as $r) {
            $total = bcadd($total, (string) $r->amount, 2);
        }

        return ['count' => $rows->count(), 'total' => $total];
    }

    public function pendingClaimList(int $limit = 6): Collection
    {
        return InsuranceClaim::whereIn('status', [ClaimStatus::Submitted->value, ClaimStatus::Approved->value])
            ->with(['provider:id,name', 'patient:id,uuid,first_name,last_name'])
            ->latest('created_at')->limit($limit)->get();
    }

    // ── Pharmacy ────────────────────────────────────────────────
    public function lowStockCount(): int
    {
        return StockItem::where('is_active', true)->lowStock()->count();
    }

    public function lowStockList(int $limit = 8): Collection
    {
        return StockItem::where('is_active', true)->lowStock()
            ->orderBy('current_quantity')->limit($limit)
            ->get(['id', 'uuid', 'name', 'unit', 'current_quantity', 'reorder_level']);
    }

    public function expiringCount(int $days = 90): int
    {
        return StockItem::where('is_active', true)
            ->expiringBefore(Carbon::now()->addDays($days))->count();
    }

    public function expiringList(int $days = 90, int $limit = 8): Collection
    {
        return StockItem::where('is_active', true)
            ->expiringBefore(Carbon::now()->addDays($days))
            ->orderBy('expiry_date')->limit($limit)
            ->get(['id', 'uuid', 'name', 'unit', 'expiry_date', 'current_quantity']);
    }

    /** @return array{total_value:string,items:int,low_stock:int,expiring:int} */
    public function stockValuation(): array
    {
        return $this->reports->stockValuation();
    }

    public function dispensationsToday(): int
    {
        return Dispensation::whereBetween('created_at', $this->todayRange())->count();
    }

    /** @return list<array{name:string,qty:string}> top dispensed today */
    public function topDrugsToday(int $limit = 6): array
    {
        $rows = DispensationItem::whereHas('dispensation', fn ($q) => $q->whereBetween('created_at', $this->todayRange()))
            ->selectRaw('name, sum(quantity) as qty')->groupBy('name')
            ->orderByDesc('qty')->limit($limit)->get();

        return $rows->map(fn ($r) => ['name' => $r->name, 'qty' => (string) $r->getAttribute('qty')])->all();
    }

    // ── Laboratory ──────────────────────────────────────────────
    public function labPendingCount(): int
    {
        return LabOrder::whereIn('status', [
            LabOrderStatus::Ordered->value, LabOrderStatus::Collected->value, LabOrderStatus::Processing->value,
        ])->count();
    }

    /** @return array<string,int> stage => count (non-terminal) */
    public function labByStage(): array
    {
        $stages = [LabOrderStatus::Ordered->value, LabOrderStatus::Collected->value, LabOrderStatus::Processing->value];
        $counts = LabOrder::whereIn('status', $stages)
            ->selectRaw('status, count(*) as c')->groupBy('status')
            ->pluck('c', 'status')->map(fn ($c) => (int) $c)->toArray();
        $out = [];
        foreach ($stages as $s) {
            $out[$s] = $counts[$s] ?? 0;
        }

        return $out;
    }

    public function labWorklist(int $limit = 8): Collection
    {
        return LabOrder::whereIn('status', [
            LabOrderStatus::Ordered->value, LabOrderStatus::Collected->value, LabOrderStatus::Processing->value,
        ])->with('patient:id,uuid,first_name,last_name')->oldest('created_at')->limit($limit)->get();
    }

    public function labCompletedToday(): int
    {
        return LabOrder::where('status', LabOrderStatus::Completed->value)
            ->whereBetween('completed_at', $this->todayRange())->count();
    }

    public function labAbnormalRecent(int $limit = 6): Collection
    {
        return LabOrderItem::whereIn('result_flag', ['low', 'high', 'abnormal'])
            ->with('order:id,uuid,patient_id')
            ->latest('resulted_at')->limit($limit)->get(['id', 'lab_order_id', 'name', 'result_value', 'result_flag', 'resulted_at']);
    }

    // ── Radiology ───────────────────────────────────────────────
    public function radPendingCount(): int
    {
        return RadiologyOrder::whereIn('status', [
            RadiologyOrderStatus::Ordered->value, RadiologyOrderStatus::Scheduled->value,
        ])->count();
    }

    public function radAwaitingReport(): int
    {
        return RadiologyOrder::where('status', RadiologyOrderStatus::Performed->value)->count();
    }

    public function radReportQueue(int $limit = 8): Collection
    {
        return RadiologyOrder::where('status', RadiologyOrderStatus::Performed->value)
            ->with('patient:id,uuid,first_name,last_name')->oldest('created_at')->limit($limit)->get();
    }

    public function radReportedToday(): int
    {
        return RadiologyOrder::where('status', RadiologyOrderStatus::Reported->value)
            ->whereBetween('reported_at', $this->todayRange())->count();
    }

    // ── Inpatient / IPD ─────────────────────────────────────────
    public function currentInpatients(): int
    {
        return Admission::where('status', AdmissionStatus::Admitted->value)->count();
    }

    public function admissionsToday(): int
    {
        return Admission::whereBetween('admitted_at', $this->todayRange())->count();
    }

    public function dischargesToday(): int
    {
        return Admission::where('status', AdmissionStatus::Discharged->value)
            ->whereBetween('discharged_at', $this->todayRange())->count();
    }

    public function inpatientsForDoctor(int $doctorId): int
    {
        return Admission::where('status', AdmissionStatus::Admitted->value)
            ->where('admitting_doctor_id', $doctorId)->count();
    }

    /** @return array{total:int,occupied:int,available:int,rate:int} */
    public function occupancy(): array
    {
        return $this->reports->occupancy();
    }

    // ── Super-admin (SaaS, cross-tenant) ────────────────────────
    // These run in the super-admin (null tenant) context; no HospitalScope filter.
    public function saasHospitalsTotal(): int
    {
        return \App\Models\Hospital::count();
    }

    /** @return array<string,int> */
    public function saasHospitalsByStatus(): array
    {
        return \App\Models\Hospital::selectRaw('status, count(*) as c')->groupBy('status')
            ->pluck('c', 'status')->map(fn ($c) => (int) $c)->toArray();
    }

    /** @return array<string,int> subscription status => count */
    public function saasSubscriptionsByStatus(): array
    {
        return \App\Models\Subscription::withoutGlobalScopes()
            ->selectRaw('status, count(*) as c')->groupBy('status')
            ->pluck('c', 'status')->map(fn ($c) => (int) $c)->toArray();
    }

    public function saasTrialsExpiringSoon(int $days = 7): int
    {
        return \App\Models\Subscription::withoutGlobalScopes()
            ->where('status', 'trialing')
            ->whereNotNull('trial_ends_at')
            ->whereBetween('trial_ends_at', [Carbon::now(), Carbon::now()->addDays($days)])
            ->count();
    }

    /** @return list<array{name:string,subscribers:int,price:string}> */
    public function saasSubscribersPerPlan(): array
    {
        return \App\Models\Plan::withCount(['subscriptions' => fn ($q) => $q->withoutGlobalScopes()->where('status', 'active')])
            ->orderByDesc('subscriptions_count')->get()
            ->map(fn ($p) => ['name' => $p->name, 'subscribers' => (int) $p->subscriptions_count, 'price' => (string) $p->price])
            ->all();
    }

    /** Estimated MRR: active subs × their plan price (normalised to monthly). */
    public function saasMrrEstimate(): string
    {
        $subs = \App\Models\Subscription::withoutGlobalScopes()
            ->where('status', 'active')->with('plan:id,price,billing_cycle')->get();
        $mrr = '0.00';
        foreach ($subs as $s) {
            $price = (string) ($s->plan->price ?? '0');
            $monthly = ($s->plan?->billing_cycle?->value === 'yearly') ? bcdiv($price, '12', 2) : $price;
            $mrr = bcadd($mrr, $monthly, 2);
        }

        return $mrr;
    }
}
