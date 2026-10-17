<?php

namespace App\Support;

use App\Models\Hospital;
use Illuminate\Support\Carbon;

/**
 * The first times that say a trial has become a working clinic: its first
 * patient registered and its first visit paid (roadmap 02 §6). Stamped once
 * on the hospital, never moved, and read by the funnel on /super/traffic
 * beside the traffic steps that came before them.
 *
 * Written with a conditional update rather than read-then-write, so two
 * receptionists registering at the same moment cannot both be "first", and
 * never allowed to fail the work that triggered it.
 */
final class Funnel
{
    public const MILESTONES = [
        'first_patient' => 'first_patient_at',
        'first_paid_visit' => 'first_paid_visit_at',
    ];

    public static function reached(?int $hospitalId, string $milestone): void
    {
        $column = self::MILESTONES[$milestone] ?? null;
        if ($hospitalId === null || $column === null) {
            return;
        }

        try {
            Hospital::withoutGlobalScopes()->whereKey($hospitalId)->whereNull($column)->update([$column => Carbon::now()]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
