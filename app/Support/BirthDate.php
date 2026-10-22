<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * A date of birth worked out from an age typed at the desk (docs/patients.md,
 * "Age or date of birth").
 *
 * Years alone give 1 July of the year that makes the age right today: the
 * middle of the year is never more than six months out. Months (a baby under
 * two) give the date that many years and months back. Either way the record
 * is flagged `dob_estimated`, so nobody prints it as a known birthday.
 */
final class BirthDate
{
    /** Ages above this are a typing mistake. */
    public const MAX_YEARS = 130;

    /** Months are asked for under this age in years. */
    public const MONTHS_UNDER = 2;

    /**
     * The estimated date as Y-m-d, or null when no age was given.
     */
    public static function fromAge(int|string|null $years, int|string|null $months = null, ?Carbon $today = null): ?string
    {
        $years = self::whole($years);
        $months = self::whole($months);
        if ($years === null && $months === null) {
            return null;
        }

        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $years ??= 0;

        if ($months !== null) {
            return $today->copy()->subYearsNoOverflow($years)->subMonthsNoOverflow($months)->toDateString();
        }

        // 1 July of the year that gives exactly this age today.
        $born = Carbon::create($today->year - $years, 7, 1)->startOfDay();
        if ($born->gt($today->copy()->subYearsNoOverflow($years))) {
            $born = $born->subYear();
        }

        return $born->toDateString();
    }

    /** Whole years and leftover months between a date of birth and today. @return array{0:int,1:int} */
    public static function ageOf(Carbon $dob, ?Carbon $today = null): array
    {
        $diff = $dob->copy()->startOfDay()->diff(($today ?? Carbon::today())->copy()->startOfDay());

        return [(int) $diff->y, (int) $diff->m];
    }

    private static function whole(int|string|null $value): ?int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return max(0, (int) $value);
    }
}
