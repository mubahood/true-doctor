<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A calendar date, stored as `Y-m-d` and nothing else.
 *
 * Eloquent's own `date` cast writes `Y-m-d H:i:s`, which a MySQL DATE column
 * quietly trims and SQLite keeps — so `date <= '2026-10-14'` finds the day's
 * rows on one and misses them on the other. Ledger balances are range sums
 * over exactly that comparison, and must read the same everywhere.
 *
 * @implements CastsAttributes<Carbon, mixed>
 */
class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse((string) $value)->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toDateString();
    }
}
