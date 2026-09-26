<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The period a report covers — the presets people ask for daily, and how a
 * typed range is read — in one place for the web's reports page, its PDF and
 * the app.
 */
final class ReportRange
{
    /** @return array<string,string> key => label */
    public static function presets(): array
    {
        return [
            'today' => 'Today',
            'week' => 'This week',
            'month' => 'This month',
            'last-month' => 'Last month',
            'quarter' => 'This quarter',
            'year' => 'This year',
        ];
    }

    /** @return array{0:string,1:string} the two dates a preset means */
    public static function forPreset(string $key): array
    {
        $now = Carbon::now();

        [$from, $to] = match ($key) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'last-month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'quarter' => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };

        return [$from->toDateString(), $to->toDateString()];
    }

    /**
     * A typed range: empty or unreadable falls back to this month. In a
     * finished request (the PDF, the app) back to front is a typo, not a
     * request for nothing, and is swapped; on the live page it is a range
     * half-way through being edited, and is left alone.
     *
     * @return array{0:string,1:string}
     */
    public static function read(?string $from, ?string $to, bool $swap = true): array
    {
        $f = self::date($from, Carbon::now()->startOfMonth());
        $t = self::date($to, Carbon::now()->endOfMonth());

        return ! $swap || $f->lte($t) ? [$f->toDateString(), $t->toDateString()] : [$t->toDateString(), $f->toDateString()];
    }

    /** Which preset a range happens to be, so one can look chosen. */
    public static function presetOf(string $from, string $to): ?string
    {
        foreach (array_keys(self::presets()) as $key) {
            if (self::forPreset($key) === [$from, $to]) {
                return $key;
            }
        }

        return null;
    }

    private static function date(?string $value, Carbon $default): Carbon
    {
        if ($value === null || trim($value) === '') {
            return $default;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return $default;
        }
    }
}
