<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Exceptions\InvalidStatusTransitionException;
use App\Exceptions\SlotUnavailableException;
use App\Models\Appointment;
use App\Models\AppointmentStatusHistory;
use App\Models\DoctorSchedule;
use App\Models\Order;
use App\Models\Room;
use App\Models\Visit;
use App\Support\CurrentHospital;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Booking + lifecycle for appointments. Booking runs inside a transaction that
 * first locks the doctor's availability rows (and the room, if any) — this both
 * validates against the template and serializes concurrent bookings for the same
 * doctor/room, so overlap checks can't race (no double-booking). Every state
 * change goes through transition(), which enforces AppointmentStatus's machine
 * and appends to the history table.
 */
class AppointmentService
{
    /** @param array{patient_id:int,origin_visit_id?:int|null,doctor_user_id:int,scheduled_at:string,duration_minutes:int,room_id?:int|null,department_id?:int|null,source?:string,reason?:string|null} $data */
    public function book(array $data, ?int $bookedBy = null): Appointment
    {
        if (app(CurrentHospital::class)->id() === null) {
            throw new RuntimeException('Cannot book an appointment with no resolved hospital.');
        }

        $start = Carbon::parse($data['scheduled_at']);
        $duration = (int) $data['duration_minutes'];
        if ($duration <= 0) {
            throw new RuntimeException('Duration must be greater than zero.');
        }
        $end = $start->copy()->addMinutes($duration);

        // A follow-up arranged during a visit carries that visit with it. The
        // patient comes FROM the visit rather than being asserted beside it —
        // an appointment whose origin says one person and whose patient says
        // another is a record nobody can act on.
        $origin = null;
        if (filled($data['origin_visit_id'] ?? null)) {
            $origin = Visit::find((int) $data['origin_visit_id']);

            if ($origin === null) {
                throw new RuntimeException('That visit is not one of this hospital’s.');
            }

            $data['patient_id'] = $origin->patient_id;
        }

        // Row locks on an empty range do not exclude a concurrent insert under
        // READ COMMITTED; an atomic lock per doctor/day closes that gap (K2).
        $lock = \Illuminate\Support\Facades\Cache::lock(
            'appt:'.app(CurrentHospital::class)->id().':'.$data['doctor_user_id'].':'.$start->toDateString(), 10
        );

        return $lock->block(5, fn () => DB::transaction(function () use ($data, $origin, $start, $end, $duration, $bookedBy) {
            $this->assertBookable((int) $data['doctor_user_id'], $data['room_id'] ?? null, $start, $end);

            $appointment = Appointment::create([
                'uuid' => (string) Str::uuid(),
                'patient_id' => $data['patient_id'],
                'origin_visit_id' => $origin?->id,
                'doctor_user_id' => $data['doctor_user_id'],
                'department_id' => $data['department_id'] ?? null,
                'room_id' => $data['room_id'] ?? null,
                'scheduled_at' => $start,
                'ends_at' => $end,
                'duration_minutes' => $duration,
                'source' => $data['source'] ?? 'walk_in',
                'status' => AppointmentStatus::Scheduled,
                'reason' => $data['reason'] ?? null,
                'created_by' => $bookedBy,
            ]);

            $this->record($appointment, null, AppointmentStatus::Scheduled, 'Booked', $bookedBy);

            return $appointment;
        }));
    }

    /** Move an existing appointment to a new time/room, re-validating the slot. */
    public function reschedule(Appointment $appointment, string $scheduledAt, int $duration, ?int $roomId, ?int $by = null): Appointment
    {
        if ($appointment->status->isTerminal()) {
            throw new RuntimeException('A completed, cancelled or no-show appointment cannot be rescheduled.');
        }

        $start = Carbon::parse($scheduledAt);
        $end = $start->copy()->addMinutes($duration);

        return DB::transaction(function () use ($appointment, $start, $end, $duration, $roomId, $by) {
            $this->assertBookable((int) $appointment->doctor_user_id, $roomId, $start, $end, $appointment->id);

            $appointment->update([
                'scheduled_at' => $start,
                'ends_at' => $end,
                'duration_minutes' => $duration,
                'room_id' => $roomId,
            ]);

            $this->record($appointment, $appointment->status, $appointment->status, 'Rescheduled to '.$start->format('d M Y H:i'), $by);

            return $appointment;
        });
    }

    public function transition(Appointment $appointment, AppointmentStatus $to, ?int $by = null, ?string $note = null): Appointment
    {
        return DB::transaction(function () use ($appointment, $to, $by, $note) {
            /** @var Appointment $locked */
            $locked = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw InvalidStatusTransitionException::between($from, $to);
            }

            // An appointment is not finished by saying so. It is finished by
            // recording what was done at it, which is a completed consultation
            // order on its visit (recordOutcome). The rule lives here rather
            // than on a screen so no screen, controller or API can complete an
            // appointment that holds no record of anything and bills nothing.
            if ($to === AppointmentStatus::Completed && ! $this->hasOutcome($locked)) {
                throw new RuntimeException(
                    'Record the outcome first — an appointment is completed by saying what was done at it.',
                );
            }

            $locked->status = $to;
            match ($to) {
                AppointmentStatus::CheckedIn => $locked->checked_in_at = now(),
                AppointmentStatus::InProgress => $locked->started_at = now(),
                AppointmentStatus::Completed => $locked->completed_at = now(),
                AppointmentStatus::Cancelled, AppointmentStatus::NoShow => $locked->cancelled_at = now(),
                default => null,
            };
            if ($to === AppointmentStatus::Cancelled && $note) {
                $locked->cancel_reason = $note;
            }
            $locked->save();

            $this->record($locked, $from, $to, $note, $by);

            return $locked;
        });
    }

    // ── What actually happened at it ─────────────────────────────────────

    /** Does this appointment's visit carry a finished consultation? */
    public function hasOutcome(Appointment $appointment): bool
    {
        $visit = $appointment->visit()->first();

        if ($visit === null) {
            return false;
        }

        return Order::where('visit_id', $visit->id)
            ->where('type', OrderType::Consultation->value)
            ->where('status', OrderStatus::Completed->value)
            ->exists();
    }

    /**
     * The visit this appointment is being seen in, opening one if it has none.
     *
     * An appointment is a promise to see somebody; a visit is the seeing. They
     * were already linked (`visits.appointment_id`, unique) but only from the
     * detail page, by hand — so an appointment could be marked Completed with
     * no record of what was done and nothing billed for it.
     */
    public function visitFor(Appointment $appointment, ?int $by = null): Visit
    {
        $existing = $appointment->visit()->first();

        if ($existing !== null) {
            return $existing;
        }

        return app(VisitService::class)->open([
            'patient_id' => $appointment->patient_id,
            'appointment_id' => $appointment->id,
            'doctor_user_id' => $appointment->doctor_user_id,
            'department_id' => $appointment->department_id,
            'reason' => $appointment->reason,
        ], $by);
    }

    /** The consultation order this appointment is being written up on, if open. */
    public function openConsultationFor(Appointment $appointment): ?Order
    {
        $visit = $appointment->visit()->first();

        if ($visit === null) {
            return null;
        }

        return Order::with('items')
            ->where('visit_id', $visit->id)
            ->where('type', OrderType::Consultation->value)
            ->whereIn('status', [OrderStatus::Pending->value, OrderStatus::InProgress->value])
            ->latest('id')
            ->first();
    }

    /**
     * Write up what was done at the appointment.
     *
     * "Completed" as a bare button said an appointment happened and nothing
     * else: no findings, no services, nothing to bill, nothing for the next
     * clinician to read. So finishing one now writes the same thing any other
     * piece of clinical work writes — an ORDER on the visit, of type
     * Consultation, carrying the doctor's report and everything the patient
     * was given, whether that is a SERVICE off the price list or a PRODUCT off
     * the pharmacy shelf. A product moves stock as well as money, in the same
     * transaction, which is exactly why the order model does it and this does
     * not (docs/orders.md).
     *
     * $complete = false saves the write-up and leaves the appointment with the
     * doctor: a consultation is not always finished in one sitting, and the
     * choice is between letting them save halfway and watching them lose it.
     * A second call finds the order it already started rather than opening a
     * second one.
     *
     * @param  list<array{kind?:string,id:int,quantity?:string}>  $lines
     */
    public function recordOutcome(Appointment $appointment, ?string $report, array $lines = [], ?int $by = null, bool $complete = true): Order
    {
        return DB::transaction(function () use ($appointment, $report, $lines, $by, $complete) {
            // Read the status back rather than trusting the instance handed
            // in: a caller that transitioned it a moment ago still holds the
            // status it had BEFORE, and a guard decided on a stale value is no
            // guard at all.
            $appointment = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if ($appointment->status->isTerminal()) {
                throw new RuntimeException('This appointment is already '.strtolower($appointment->status->label()).'.');
            }

            if (! in_array($appointment->status, [AppointmentStatus::CheckedIn, AppointmentStatus::InProgress], true)) {
                throw new RuntimeException('The patient has to arrive before there is anything to report. Check them in first.');
            }

            $visit = $this->visitFor($appointment, $by);
            $orders = app(OrderService::class);

            // Picking up where an unfinished write-up left off, rather than
            // opening a second consultation for the same attendance.
            $order = $this->openConsultationFor($appointment) ?? $orders->place(
                $visit,
                OrderType::Consultation,
                'Consultation — '.$appointment->scheduled_at->format('j M Y'),
                ['status' => OrderStatus::InProgress, 'assigned_to' => $appointment->doctor_user_id],
                $by,
            );

            $orders->saveReport($order, $report);

            // The lines given are the whole list, so what was taken off the
            // dialog comes off the order — and a product coming off puts its
            // stock back, which is updateItem/removeItem's job, not ours.
            $orders->syncLines($order, $lines, $by);

            // CheckedIn cannot reach Completed in one step, and it should not:
            // the patient was with the doctor, and the trail should say so.
            if ($appointment->status === AppointmentStatus::CheckedIn) {
                $this->transition($appointment, AppointmentStatus::InProgress, $by);
            }

            if ($complete) {
                $orders->complete($order, [], $by);
                $this->transition($appointment, AppointmentStatus::Completed, $by, 'Outcome recorded.');
            }

            return $order->fresh(['items']);
        });
    }

    /**
     * Validate a [start,end) slot for a doctor (and optional room): inside an
     * active availability window, aligned to the slot grid, and not overlapping
     * any occupying booking. Assumes an open transaction — takes row locks.
     */
    private function assertBookable(int $doctorId, ?int $roomId, Carbon $start, Carbon $end, ?int $ignoreId = null): void
    {
        // Lock the doctor's windows for this weekday — serializes concurrent books.
        $windows = DoctorSchedule::where('user_id', $doctorId)
            ->where('weekday', $start->dayOfWeek)
            ->where('is_active', true)
            ->lockForUpdate()
            ->get();

        $match = null;
        foreach ($windows as $w) {
            $winStart = $start->copy()->setTimeFromTimeString($w->start_time);
            $winEnd = $start->copy()->setTimeFromTimeString($w->end_time);
            if ($start < $winStart || $end > $winEnd) {
                continue;
            }
            $offset = $winStart->diffInMinutes($start);
            if ($offset % $w->slot_minutes !== 0) {
                throw SlotUnavailableException::misaligned($w->slot_minutes);
            }
            $match = $w;
            break;
        }
        if ($match === null) {
            throw SlotUnavailableException::outsideAvailability();
        }

        if ($roomId !== null) {
            Room::whereKey($roomId)->lockForUpdate()->first();
        }

        $occupying = array_map(fn ($s) => $s->value, array_filter(
            AppointmentStatus::cases(),
            fn (AppointmentStatus $s) => $s->occupiesSlot(),
        ));

        $doctorClash = Appointment::where('doctor_user_id', $doctorId)
            ->where('scheduled_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->whereIn('status', $occupying)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->lockForUpdate()
            ->exists();
        if ($doctorClash) {
            throw SlotUnavailableException::doctorBusy();
        }

        if ($roomId !== null) {
            $roomClash = Appointment::where('room_id', $roomId)
                ->where('scheduled_at', '<', $end)
                ->where('ends_at', '>', $start)
                ->whereIn('status', $occupying)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->lockForUpdate()
                ->exists();
            if ($roomClash) {
                throw SlotUnavailableException::roomBusy();
            }
        }
    }

    private function record(Appointment $appointment, ?AppointmentStatus $from, AppointmentStatus $to, ?string $note, ?int $by): void
    {
        AppointmentStatusHistory::create([
            'appointment_id' => $appointment->id,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'changed_by' => $by,
            'created_at' => now(),
        ]);
    }
}
