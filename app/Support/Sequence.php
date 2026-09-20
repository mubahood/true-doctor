<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Gap-free, race-free document numbering (invoice/visit/patient/claim
 * numbers). One row per (hospital, key, period) in `sequences`, incremented
 * under a row lock — replaces the non-sargable, racy
 * `whereYear(created_at)->count() + 1` pattern (plan finding K1).
 *
 * The first allocation for a period can be seeded from existing data so a
 * tenant that already issued INV-2026-00042 continues at 00043.
 */
final class Sequence
{
    /**
     * @param  callable(): int|null  $seed  Existing count for the period (used once, on row creation).
     */
    public static function next(string $key, string $period, ?callable $seed = null): int
    {
        $hospitalId = app(CurrentHospital::class)->id();

        $run = function () use ($key, $period, $hospitalId, $seed): int {
            $base = DB::table('sequences')
                ->where('key', $key)
                ->where('period', $period)
                ->when($hospitalId === null, fn ($q) => $q->whereNull('hospital_id'), fn ($q) => $q->where('hospital_id', $hospitalId));

            $row = (clone $base)->lockForUpdate()->first();

            if ($row === null) {
                DB::table('sequences')->insertOrIgnore([
                    'hospital_id' => $hospitalId,
                    'key' => $key,
                    'period' => $period,
                    'next_value' => $seed ? max(0, (int) $seed()) : 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $row = (clone $base)->lockForUpdate()->first();
            }

            $value = (int) $row->next_value + 1;
            DB::table('sequences')->where('id', $row->id)->update(['next_value' => $value, 'updated_at' => now()]);

            return $value;
        };

        return DB::transactionLevel() > 0 ? $run() : DB::transaction($run);
    }
}
