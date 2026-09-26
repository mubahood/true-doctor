<?php

namespace App\Support;

use App\Enums\AppointmentStatus;
use App\Enums\Weekday;
use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * When a doctor can be booked — the web's booking dialog and the app offer
 * the same days and times from here.
 *
 * Times are generated exactly the way AppointmentService::assertBookable
 * checks them — the window's own slot grid, the whole appointment inside one
 * window, nothing overlapping a booking that still occupies its slot — so a
 * time offered is a time that books.
 */
final class DoctorSlots
{
    /**
     * The next fortnight, saying which days the doctor sits.
     *
     * @return list<array{date:string, label:string, sub:string, sits:bool}>
     */
    public static function days(?int $doctorId): array
    {
        $sitsOn = $doctorId === null ? null : DoctorSchedule::query()
            ->where('user_id', $doctorId)
            ->where('is_active', true)
            ->pluck('weekday')
            ->map(fn ($d) => $d instanceof Weekday ? $d->value : (int) $d)
            ->all();

        $days = [];
        for ($i = 0; $i < 14; $i++) {
            $day = now()->startOfDay()->addDays($i);
            $days[] = [
                'date' => $day->format('Y-m-d'),
                'label' => match ($i) {
                    0 => 'Today',
                    1 => 'Tomorrow',
                    default => $day->format('D'),
                },
                'sub' => $i < 2 ? $day->format('D j M') : $day->format('j M'),
                'sits' => $sitsOn === null || in_array($day->dayOfWeek, $sitsOn, true),
            ];
        }

        return $days;
    }

    /**
     * The times this doctor is free on this day at this length, soonest first.
     *
     * @param  int|null  $ignore  an appointment being moved: its own time stays one of the answers
     * @return list<string> "HH:MM"
     */
    public static function open(int $doctorId, string $date, int $length, ?int $ignore = null): array
    {
        $day = Carbon::parse($date)->startOfDay();
        $length = max(5, $length);

        $windows = DoctorSchedule::query()
            ->where('user_id', $doctorId)
            ->where('weekday', $day->dayOfWeek)
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get();

        if ($windows->isEmpty()) {
            return [];
        }

        $taken = Appointment::query()
            ->where('doctor_user_id', $doctorId)
            ->whereDate('scheduled_at', $day->toDateString())
            ->when($ignore !== null, fn ($q) => $q->where('id', '!=', $ignore))
            ->whereIn('status', array_map(
                fn (AppointmentStatus $s) => $s->value,
                array_filter(AppointmentStatus::cases(), fn (AppointmentStatus $s) => $s->occupiesSlot()),
            ))
            ->get(['scheduled_at', 'ends_at']);

        $slots = [];
        foreach ($windows as $window) {
            $cursor = $day->copy()->setTimeFromTimeString($window->start_time);
            $closes = $day->copy()->setTimeFromTimeString($window->end_time);

            while ($cursor->copy()->addMinutes($length) <= $closes) {
                $ends = $cursor->copy()->addMinutes($length);
                $free = ! $cursor->isPast()
                    && ! $taken->contains(fn ($a) => $a->scheduled_at < $ends && $a->ends_at > $cursor);

                if ($free) {
                    $slots[$cursor->format('H:i')] = true;
                }

                $cursor->addMinutes($window->slot_minutes);
            }
        }

        ksort($slots);

        return array_keys($slots);
    }

    /**
     * Why there are no times, in the words of whatever is actually missing —
     * "no times" with nothing beside it sends people looking for a bug.
     */
    public static function note(?int $doctorId, ?string $date, int $length): ?string
    {
        if ($doctorId === null) {
            return 'Choose a doctor to see the times they are free.';
        }
        if ($date === null) {
            return 'Choose a day to see the times they are free.';
        }
        if (self::open($doctorId, $date, $length) !== []) {
            return null;
        }

        $name = (string) (User::currentHospital()->whereKey($doctorId)->value('name') ?: 'This doctor');
        $day = Carbon::parse($date);

        $sits = DoctorSchedule::query()
            ->where('user_id', $doctorId)
            ->where('weekday', $day->dayOfWeek)
            ->where('is_active', true)
            ->exists();

        if (! $sits) {
            return $name.' has no availability on '.$day->format('l').'s. Set it on Doctor availability.';
        }

        return $name.' is fully booked on '.$day->format('D j M')
            .' at '.$length.' minutes. Try a shorter appointment or another day.';
    }
}
