<?php

namespace App\Support;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Today's check-in queue — one definition for the web board and the app.
 *
 * A queue answers two questions a list cannot: WHO IS WAITING, and HOW LONG
 * HAVE THEY BEEN. Three lanes in the order a desk thinks in — with the doctor
 * now, waiting (longest first), still expected — each row with its own clock.
 *
 * It used to live inside the Livewire board (App\Livewire\Appointments\Queue),
 * where the app could only have copied it; the board and GET /api/v1/queue
 * now both ask this, so a threshold or an order changed here changes both.
 */
final class CheckInQueue
{
    /** Statuses that still belong on the queue. */
    public const ACTIVE = [
        AppointmentStatus::Scheduled,
        AppointmentStatus::Confirmed,
        AppointmentStatus::CheckedIn,
        AppointmentStatus::InProgress,
    ];

    /** Waiting longer than this is the thing the board exists to show. */
    public const WAIT_WARN = 20;

    public const WAIT_BAD = 45;

    /** @return Collection<int,Appointment> today's appointments still on the queue */
    public function rows(?int $doctorId = null): Collection
    {
        return Appointment::with(['patient', 'doctor', 'room'])
            ->forDate(now()->format('Y-m-d'))
            ->whereIn('status', array_map(fn (AppointmentStatus $s) => $s->value, self::ACTIVE))
            ->when($doctorId !== null, fn (Builder $q) => $q->where('doctor_user_id', $doctorId))
            ->orderBy('scheduled_at')
            ->get();
    }

    /**
     * The board, in the order a desk thinks about it.
     *
     * With the doctor first because that is what is happening now; waiting
     * next, LONGEST FIRST, because that is the queue and the person who has
     * been there an hour is the one being failed; expected last, in clock
     * order, because it has not happened yet.
     *
     * @param  Collection<int,Appointment>  $rows
     * @return array<string,array{title:string,note:string,rows:Collection<int,Appointment>}>
     */
    public function lanes(Collection $rows): array
    {
        $seeing = $rows->where('status', AppointmentStatus::InProgress);
        $waiting = $rows->where('status', AppointmentStatus::CheckedIn)
            ->sortBy(fn (Appointment $a) => ($a->checked_in_at ?? $a->scheduled_at)->timestamp);
        $expected = $rows->whereIn('status', [AppointmentStatus::Scheduled, AppointmentStatus::Confirmed]);

        return [
            'seeing' => ['title' => 'With the doctor', 'note' => 'Being seen now.', 'rows' => $seeing->values()],
            'waiting' => ['title' => 'Waiting', 'note' => 'Arrived and not yet called. Longest wait first.', 'rows' => $waiting->values()],
            'expected' => ['title' => 'Expected', 'note' => 'Booked for today and not arrived.', 'rows' => $expected->values()],
        ];
    }

    /**
     * How long this patient has been sitting, in minutes — from the moment
     * they were checked in, not their appointment time, which is what they
     * were promised rather than what happened.
     */
    public function waitedMinutes(Appointment $appointment): ?int
    {
        return $appointment->checked_in_at === null
            ? null
            : max(0, (int) $appointment->checked_in_at->diffInMinutes(now()));
    }

    /** How late an expected patient is, in minutes — negative if still to come. */
    public function lateMinutes(Appointment $appointment): int
    {
        return (int) $appointment->scheduled_at->diffInMinutes(now(), false);
    }

    /** '', 'warn' or 'bad' — how a wait should read at a glance. */
    public function waitTone(?int $minutes): string
    {
        return match (true) {
            $minutes === null => '',
            $minutes >= self::WAIT_BAD => 'bad',
            $minutes >= self::WAIT_WARN => 'warn',
            default => '',
        };
    }

    /** "1h 05m" reads faster than "65 minutes" on a board glanced at. */
    public function clock(int $minutes): string
    {
        return $minutes < 60
            ? $minutes.'m'
            : intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    }

    /**
     * What the desk needs to know without counting rows.
     *
     * @param  array<string,array{rows:Collection<int,Appointment>}>  $lanes
     * @return array{expected:int,waiting:int,seeing:int,longest:int|null,seen:int}
     */
    public function tally(array $lanes, ?int $doctorId = null): array
    {
        $waits = $lanes['waiting']['rows']->map(fn (Appointment $a) => $this->waitedMinutes($a) ?? 0);

        return [
            'expected' => $lanes['expected']['rows']->count(),
            'waiting' => $lanes['waiting']['rows']->count(),
            'seeing' => $lanes['seeing']['rows']->count(),
            'longest' => $waits->isEmpty() ? null : (int) $waits->max(),
            'seen' => Appointment::forDate(now()->format('Y-m-d'))
                ->where('status', AppointmentStatus::Completed->value)
                ->when($doctorId !== null, fn (Builder $q) => $q->where('doctor_user_id', $doctorId))
                ->count(),
        ];
    }
}
