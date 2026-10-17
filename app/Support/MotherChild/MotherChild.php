<?php

namespace App\Support\MotherChild;

use App\Models\Hospital;
use App\Models\User;
use App\Support\CurrentHospital;
use App\Support\Practice\Practice;
use Illuminate\Support\Carbon;

/**
 * Small shared helpers for the pregnancy record, the immunisation register
 * and the growth chart (docs/mother-child.md).
 */
final class MotherChild
{
    /** Who may read these records: the same people who read the clinical history. */
    public static function canView(?User $user): bool
    {
        return $user !== null && $user->can('visits.view');
    }

    /** Who may write them: the nurse or midwife at triage, or the clinician. */
    public static function canRecord(?User $user): bool
    {
        return $user !== null && ($user->can('visits.vitals') || $user->can('visits.diagnose'));
    }

    /** The hospital in context (web and API alike). */
    public static function hospital(): ?Hospital
    {
        $id = app(CurrentHospital::class)->id();

        return $id === null ? null : Hospital::query()->find($id);
    }

    /**
     * Whether the practice cares for mothers or children at all: the maternity
     * or the paediatrics pack is on (a dental clinic: no).
     *
     * @param  list<string>  $packs
     */
    public static function practises(?Hospital $hospital, array $packs): bool
    {
        return $hospital !== null && array_intersect($packs, Practice::of($hospital)->packKeys()) !== [];
    }

    /** Whole days from one date to another (negative when $to is earlier). */
    public static function days(Carbon $from, Carbon $to): int
    {
        return (int) round($from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay(), false));
    }

    /** "3 y 2 m", "7 m", "5 w" or "3 d": an age that a nurse reads at a glance. */
    public static function ageLabel(int $days): string
    {
        if ($days < 0) {
            return '—';
        }
        if ($days < 28) {
            return $days < 14 ? "{$days} d" : intdiv($days, 7).' w';
        }
        $months = (int) floor($days / WhoGrowth::DAYS_PER_MONTH);
        if ($months < 24) {
            return "{$months} m";
        }

        return intdiv($months, 12).' y'.($months % 12 ? ' '.($months % 12).' m' : '');
    }
}
