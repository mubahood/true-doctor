<?php

namespace App\Support\Insights\Rules;

use App\Models\DoctorSchedule;
use App\Models\User;
use App\Support\Insights\Insight;
use App\Support\Insights\InsightContext;
use App\Support\Insights\Rule;

/** Active clinicians with no availability window: nobody can book them. */
final class DoctorsWithoutAvailability extends Rule
{
    public function key(): string
    {
        return 'doctors-without-availability';
    }

    protected function permission(): string
    {
        return 'schedules.manage';
    }

    public function evaluate(InsightContext $c): ?Insight
    {
        $n = User::currentHospital()->clinicians()
            ->where('is_active', true)
            ->whereNotIn('id', DoctorSchedule::query()->where('is_active', true)->select('user_id'))
            ->count();

        if ($n === 0) {
            return null;
        }

        return new Insight(
            $this->key(), 'warn',
            self::plural($n, 'doctor').' with no availability',
            'No working hours are set, so they cannot be booked for appointments.',
            'admin.schedules.index', 'Set working hours', $n, 56,
        );
    }
}
