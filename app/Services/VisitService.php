<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\VisitOutcome;
use App\Enums\VisitStage;
use App\Enums\VisitStatus;
use App\Exceptions\InvalidVisitTransitionException;
use App\Models\Patient;
use App\Models\Visit;
use App\Models\VisitStatusHistory;
use App\Support\CurrentHospital;
use App\Support\HospitalSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Opens and drives OPD visits. Visit number generation locks the day's
 * rows (like PatientService) so per-day sequences can't collide; state changes
 * go through transition() which enforces VisitStatus's machine and
 * appends to the history table. Vitals recording computes BMI once, here — never
 * as a model hook (§2.3: no cross-entity side effects on save).
 */
class VisitService
{
    public function __construct(private readonly PatientService $patients) {}

    public function open(array $data, ?int $openedBy = null): Visit
    {
        if (app(CurrentHospital::class)->id() === null) {
            throw new RuntimeException('Cannot open a visit with no resolved hospital.');
        }

        return $this->createWithNumber($data, $openedBy);
    }

    /**
     * The visit a patient's activity belongs to right now: the one still open
     * for them today, or a fresh one.
     *
     * Everything a patient does is attached to a visit, and clinical work must
     * never be blocked because the paperwork was not done first — an emergency
     * admission or a walk-in pharmacy sale arrives with no visit open, and the
     * answer is to open one, not to refuse the patient. Callers that DO know
     * the visit always pass it; this is the floor, not the normal path.
     */
    public function currentOrOpenFor(Patient $patient, ?int $openedBy = null): Visit
    {
        $open = Visit::where('patient_id', $patient->id)
            ->where('status', '!=', VisitStatus::Completed->value)
            ->latest('created_at')
            ->first();

        return $open ?? $this->open([
            'patient_id' => $patient->id,
            'reason' => 'Opened automatically for unscheduled care',
        ], $openedBy);
    }

    /**
     * Register a new patient and open their visit atomically — the legacy's
     * inline-intake flow rebuilt as an explicit two-step service call, never
     * transient patient attributes stashed on the visit model (§2.3).
     */
    public function intake(array $patientData, array $visitData, ?int $openedBy = null): Visit
    {
        if (app(CurrentHospital::class)->id() === null) {
            throw new RuntimeException('Cannot open a visit with no resolved hospital.');
        }

        return DB::transaction(function () use ($patientData, $visitData, $openedBy) {
            $patient = $this->patients->register($patientData, $openedBy);
            $visitData['patient_id'] = $patient->id;

            return $this->createWithNumber($visitData, $openedBy);
        });
    }

    private function createWithNumber(array $data, ?int $openedBy, int $attempt = 0): Visit
    {
        $now = Carbon::now();

        try {
            return DB::transaction(function () use ($data, $openedBy, $now) {
                $day = $now->toDateString();
                $seq = \App\Support\Sequence::next('visit', $day, fn () => Visit::withTrashed()->whereDate('created_at', $day)->count());

                $visit = Visit::create([
                    'uuid' => (string) Str::uuid(),
                    'visit_no' => $this->makeNumber($seq, $now),
                    'patient_id' => $data['patient_id'],
                    'appointment_id' => $data['appointment_id'] ?? null,
                    'doctor_user_id' => $data['doctor_user_id'] ?? null,
                    'receptionist_user_id' => $openedBy,
                    'department_id' => $data['department_id'] ?? null,
                    'reason' => $data['reason'] ?? null,
                    'complaints' => $data['complaints'] ?? null,
                    // Opened, not started: a visit becomes Ongoing when the
                    // first piece of work lands on it, not when it is booked.
                    'status' => VisitStatus::Pending,
                    'stage' => VisitStage::Ongoing,
                    'outcome' => null,
                ]);

                VisitStatusHistory::create([
                    'visit_id' => $visit->id,
                    'from_status' => null,
                    'to_status' => VisitStatus::Pending->value,
                    'from_stage' => null,
                    'to_stage' => VisitStage::Ongoing->value,
                    'note' => 'Opened',
                    'changed_by' => $openedBy,
                    'created_at' => $now,
                ]);

                return $visit;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($attempt >= 3) {
                throw $e;
            }

            return $this->createWithNumber($data, $openedBy, $attempt + 1);
        }
    }

    public function makeNumber(int $seq, Carbon $date): string
    {
        return 'V-'.$date->format('Ymd').'-'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    /** Record vitals and (re)compute BMI from weight (kg) and height (cm). */
    public function recordVitals(Visit $visit, array $vitals): Visit
    {
        $weight = $vitals['weight'] ?? null;
        $height = $vitals['height'] ?? null;

        $vitals['bmi'] = $this->computeBmi($weight !== null ? (float) $weight : null, $height !== null ? (float) $height : null);
        $vitals['vitals_recorded_at'] = Carbon::now();

        $visit->fill($vitals)->save();

        return $visit;
    }

    /** BMI = kg / m², rounded to 2dp; null unless both weight and a positive height are given. */
    public function computeBmi(?float $weightKg, ?float $heightCm): ?float
    {
        if ($weightKg === null || $heightCm === null || $heightCm <= 0) {
            return null;
        }

        $metres = $heightCm / 100;

        return round($weightKg / ($metres * $metres), 2);
    }

    /** Update the clinical narrative (complaints, diagnosis, remarks). */
    public function updateClinical(Visit $visit, array $data): Visit
    {
        $visit->fill(array_intersect_key($data, array_flip([
            'reason', 'complaints', 'diagnosis', 'doctor_user_id', 'department_id',
            'doctor_remarks', 'receptionist_remarks', 'patient_remarks',
        ])))->save();

        return $visit;
    }

    // ── Moving a visit along ─────────────────────────────────────────────

    /**
     * What each gate says, and what is holding it shut.
     *
     * Nobody picks a stage off a list (docs/visits.md). The system works out
     * whether the next one is reachable, and the control only appears once it
     * is. A shut gate says WHY, in figures — "2 orders are still open", "UGX
     * 41,000 still outstanding" — because a greyed-out button teaches nobody
     * anything.
     *
     * @return array{stage:VisitStage,next:?VisitStage,ready:bool,blocker:?string,label:?string,automatic:bool}
     */
    public function readiness(Visit $visit): array
    {
        $stage = $visit->stage;
        $next = $stage->next();

        [$ready, $blocker] = match ($stage) {
            VisitStage::Ongoing => $this->allOrdersFinished($visit),
            VisitStage::Billing => $this->hasLiveInvoice($visit),
            VisitStage::Payment => $this->isSettled($visit),
            VisitStage::Completed => [false, null],
        };

        return [
            'stage' => $stage,
            'next' => $next,
            'ready' => $ready && $next !== null,
            'blocker' => $blocker,
            'label' => $stage->advanceLabel(),
            // The last move is nobody's decision: a visit completes itself the
            // moment the balance reaches zero.
            'automatic' => $stage === VisitStage::Payment,
        ];
    }

    /** No order may still be open — billing work nobody has finished is how bills go wrong. */
    private function allOrdersFinished(Visit $visit): array
    {
        $open = $visit->orders()
            ->whereIn('status', [OrderStatus::Pending->value, OrderStatus::InProgress->value])
            ->count();

        return $open === 0
            ? [true, null]
            : [false, $open === 1 ? '1 order is still open.' : "{$open} orders are still open."];
    }

    private function hasLiveInvoice(Visit $visit): array
    {
        $invoice = $visit->invoices()->where('status', '!=', InvoiceStatus::Void->value)->first();

        return $invoice !== null
            ? [true, null]
            : [false, 'No invoice has been generated yet.'];
    }

    /** The rule nothing used to enforce: a visit cannot finish owing money. */
    private function isSettled(Visit $visit): array
    {
        $invoice = $visit->invoices()->where('status', '!=', InvoiceStatus::Void->value)->first();

        if ($invoice === null) {
            return [false, 'No invoice has been generated yet.'];
        }

        return bccomp((string) $invoice->balance, '0.00', 2) <= 0
            ? [true, null]
            : [false, HospitalSettings::money($invoice->balance).' still outstanding.'];
    }

    /**
     * Move to the next stage, if its gate is open.
     *
     * There is only ever one next stage, so this takes no target: pressing
     * "Ready for billing" cannot land anywhere except Billing.
     */
    public function advance(Visit $visit, ?int $by = null, ?string $note = null): Visit
    {
        return DB::transaction(function () use ($visit, $by, $note) {
            /** @var Visit $locked */
            $locked = Visit::whereKey($visit->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw new InvalidVisitTransitionException('This visit is finished.');
            }

            $gate = $this->readiness($locked);
            if (! $gate['ready']) {
                throw new InvalidVisitTransitionException(
                    $gate['blocker'] ?? 'This visit cannot move on yet.'
                );
            }

            // Every time a visit is confirmed and moved on, the shelf is
            // checked against the bill. Stock already moved when the work was
            // done — this only posts a difference if one exists, so on a visit
            // where nothing has drifted it writes nothing at all.
            app(OrderService::class)->reconcileStock($locked, $by);

            return $this->put($locked, VisitStatus::Ongoing, $gate['next'], null, $by, $note, false);
        });
    }

    /**
     * The first piece of work starts the visit.
     *
     * Called wherever work lands rather than asked for: nobody should have to
     * remember to press "start", and a visit with orders on it that still says
     * Pending is simply wrong.
     */
    public function start(Visit $visit, ?int $by = null): Visit
    {
        if ($visit->status !== VisitStatus::Pending) {
            return $visit;
        }

        return DB::transaction(function () use ($visit, $by) {
            /** @var Visit $locked */
            $locked = Visit::whereKey($visit->id)->lockForUpdate()->firstOrFail();

            return $locked->status === VisitStatus::Pending
                ? $this->put($locked, VisitStatus::Ongoing, VisitStage::Ongoing, null, $by, null, false)
                : $locked;
        });
    }

    /**
     * Apply whatever the visit's own facts now imply.
     *
     * Idempotent and safe to call after any piece of work. Only the moves that
     * are nobody's decision happen here — starting a Pending visit, and
     * completing a settled one. The two that are a judgement stay a button.
     */
    public function reconcile(Visit $visit, ?int $by = null): Visit
    {
        $visit = $visit->fresh() ?? $visit;

        if (! $visit->isOpen()) {
            return $visit;
        }

        if ($visit->status === VisitStatus::Pending) {
            $visit = $this->start($visit, $by);
        }

        if ($visit->stage === VisitStage::Payment && $this->isSettled($visit)[0]) {
            $visit = $this->settle($visit, $by);
        }

        return $visit;
    }

    /** Paid in full: the visit closes itself. */
    private function settle(Visit $visit, ?int $by): Visit
    {
        return DB::transaction(function () use ($visit, $by) {
            /** @var Visit $locked */
            $locked = Visit::whereKey($visit->id)->lockForUpdate()->firstOrFail();

            if ($locked->stage !== VisitStage::Payment || ! $this->isSettled($locked)[0]) {
                return $locked;
            }

            return $this->put($locked, VisitStatus::Completed, VisitStage::Completed,
                VisitOutcome::Closed, $by, 'Settled in full.', false);
        });
    }

    /** Call the visit off. Always available, and always takes a reason. */
    public function cancel(Visit $visit, ?int $by = null, ?string $reason = null): Visit
    {
        return DB::transaction(function () use ($visit, $by, $reason) {
            /** @var Visit $locked */
            $locked = Visit::whereKey($visit->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw new InvalidVisitTransitionException('This visit is already finished.');
            }

            return $this->put($locked, VisitStatus::Completed, $locked->stage,
                VisitOutcome::Cancelled, $by, $reason, false);
        });
    }

    /**
     * Put the visit wherever it is asked to go, gates and all.
     *
     * Everything above is the shape of a normal day. This is for the days that
     * are not: a visit finished by mistake, cancelled by the wrong desk,
     * advanced while the patient was still in the corridor. It demands a
     * reason, it is marked in the trail, and it is gated on its own permission
     * rather than on `visits.manage`.
     */
    public function overrideState(
        Visit $visit,
        VisitStatus $status,
        VisitStage $stage,
        ?VisitOutcome $outcome,
        ?int $by,
        string $reason,
    ): Visit {
        if (trim($reason) === '') {
            throw new RuntimeException('Changing a visit out of order needs a reason.');
        }

        return DB::transaction(function () use ($visit, $status, $stage, $outcome, $by, $reason) {
            /** @var Visit $locked */
            $locked = Visit::whereKey($visit->id)->lockForUpdate()->firstOrFail();

            // An outcome only means anything on a finished visit, and a
            // finished one always has one.
            $outcome = $status === VisitStatus::Completed
                ? ($outcome ?? VisitOutcome::Closed)
                : null;

            if ($locked->status === $status && $locked->stage === $stage && $locked->outcome === $outcome) {
                return $locked;
            }

            return $this->put($locked, $status, $stage, $outcome, $by, trim($reason), true);
        });
    }

    /**
     * The single writer of a visit's state, and of the row that records it.
     *
     * Every path above comes through here, so the trail can never disagree
     * with the visit: one place sets the fields, stamps the clock and appends
     * the audit row, inside whatever transaction the caller opened.
     */
    private function put(
        Visit $visit,
        VisitStatus $status,
        VisitStage $stage,
        ?VisitOutcome $outcome,
        ?int $by,
        ?string $note,
        bool $isOverride,
    ): Visit {
        $fromStatus = $visit->status;
        $fromStage = $visit->stage;

        $visit->status = $status;
        $visit->stage = $stage;
        $visit->outcome = $outcome;

        // A visit that is no longer finished has no finishing time, and
        // leaving one behind would wrong every report that counts them.
        $visit->completed_at = $status === VisitStatus::Completed ? Carbon::now() : null;
        $visit->save();

        VisitStatusHistory::create([
            'visit_id' => $visit->id,
            'from_status' => $fromStatus->value,
            'to_status' => $status->value,
            'from_stage' => $fromStage->value,
            'to_stage' => $stage->value,
            'note' => $note,
            'is_override' => $isOverride,
            'changed_by' => $by,
            'created_at' => Carbon::now(),
        ]);

        return $visit;
    }
}
