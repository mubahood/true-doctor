<?php

namespace App\Services;

use App\Enums\Weekday;
use App\Models\DoctorSchedule;
use App\Models\User;

/**
 * The rules of the weekly roster that the Doctor availability page and the API
 * share: what a repeating answer ("weekdays") means, how it fans out into one
 * window per day, and the figures the roster adds up to.
 */
class DoctorScheduleService
{
    /** Answers that mean several days at once, expanded when the form is saved. */
    public const EVERY_DAY = 'all';

    public const WEEKDAYS = 'weekdays';

    public const WEEKEND = 'weekend';

    /** @return list<string> the repeating words a create accepts */
    public static function repeatingAnswers(): array
    {
        return [self::EVERY_DAY, self::WEEKDAYS, self::WEEKEND];
    }

    /** @return list<int> the days a weekday answer actually means */
    public function daysMeant(string $weekday): array
    {
        return match ($weekday) {
            self::EVERY_DAY => [
                Weekday::Monday->value, Weekday::Tuesday->value, Weekday::Wednesday->value,
                Weekday::Thursday->value, Weekday::Friday->value, Weekday::Saturday->value,
                Weekday::Sunday->value,
            ],
            self::WEEKDAYS => [
                Weekday::Monday->value, Weekday::Tuesday->value, Weekday::Wednesday->value,
                Weekday::Thursday->value, Weekday::Friday->value,
            ],
            self::WEEKEND => [Weekday::Saturday->value, Weekday::Sunday->value],
            default => [(int) $weekday],
        };
    }

    /**
     * One window per day. A day that already has an identical window is
     * skipped rather than refused: "every day" on a doctor who already sits on
     * Monday should add the other six, not fail because of the one.
     *
     * @param  array<string,mixed>  $data  validated window fields
     * @param  list<int>  $days
     * @return array{added:int,skipped:int,windows:list<DoctorSchedule>}
     */
    public function addWindows(array $data, array $days): array
    {
        $added = 0;
        $skipped = 0;
        $windows = [];

        foreach ($days as $day) {
            $exists = DoctorSchedule::where('user_id', $data['user_id'])
                ->where('weekday', $day)
                ->where('start_time', $data['start_time'])
                ->where('end_time', $data['end_time'])
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            $windows[] = DoctorSchedule::create(array_merge($data, ['weekday' => $day]));
            $added++;
        }

        return ['added' => $added, 'skipped' => $skipped, 'windows' => $windows];
    }

    /** "08:00:00" → 480. */
    public static function minutesOf(string $time): int
    {
        [$h, $m] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        return $h * 60 + $m;
    }

    /**
     * Everything the headline figures, the coverage strip and the overlap
     * flags are derived from — the whole hospital, not a filtered page.
     *
     * @return array{
     *     doctors:int, rostered:int, unrostered:int, hours:float, slots:int,
     *     windows:int, off:int, coverage:array<int,int>, conflicts:array<int,bool>
     * }
     */
    public function facts(): array
    {
        $doctors = User::currentHospital()->clinicians()->count();

        $windows = DoctorSchedule::query()
            ->orderBy('user_id')->orderBy('weekday')->orderBy('start_time')
            ->get(['id', 'user_id', 'weekday', 'start_time', 'end_time', 'slot_minutes', 'is_active']);

        $rostered = [];
        $coverage = array_fill_keys(array_column(Weekday::cases(), 'value'), 0);
        $covering = [];
        $conflicts = [];
        $minutes = 0;
        $slots = 0;
        $off = 0;

        /** @var array<string,array{int,int}> $lastEndByDoctorDay */
        $lastEndByDoctorDay = [];

        foreach ($windows as $w) {
            $rostered[$w->user_id] = true;

            if (! $w->is_active) {
                $off++;

                continue;
            }

            $from = self::minutesOf($w->start_time);
            $to = self::minutesOf($w->end_time);
            $span = max(0, $to - $from);

            $minutes += $span;
            $slots += $w->slot_minutes > 0 ? intdiv($span, $w->slot_minutes) : 0;

            $day = $w->weekday->value;
            $covering[$day][$w->user_id] = true;

            // Sorted by doctor, day, start: an overlap is a window that begins
            // before the previous one on the same day has ended. Both ends of
            // the pair are flagged.
            $key = $w->user_id.':'.$day;
            if (isset($lastEndByDoctorDay[$key]) && $from < $lastEndByDoctorDay[$key][1]) {
                $conflicts[$w->id] = true;
                $conflicts[$lastEndByDoctorDay[$key][0]] = true;
            }
            if (! isset($lastEndByDoctorDay[$key]) || $to > $lastEndByDoctorDay[$key][1]) {
                $lastEndByDoctorDay[$key] = [$w->id, $to];
            }
        }

        foreach ($covering as $day => $ids) {
            $coverage[$day] = count($ids);
        }

        return [
            'doctors' => $doctors,
            'rostered' => count($rostered),
            'unrostered' => max(0, $doctors - count($rostered)),
            'hours' => round($minutes / 60, 1),
            'slots' => $slots,
            'windows' => $windows->count(),
            'off' => $off,
            'coverage' => $coverage,
            'conflicts' => $conflicts,
        ];
    }
}
