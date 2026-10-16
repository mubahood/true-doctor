<?php

namespace App\Support\Insights\Rules;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Support\Insights\Insight;
use App\Support\Insights\InsightContext;
use App\Support\Insights\Rule;

/** Patients marked as no-shows today: a chance to rebook them. */
final class NoShowsToday extends Rule
{
    public function key(): string
    {
        return 'no-shows-today';
    }

    protected function permission(): string
    {
        return 'appointments.view';
    }

    public function evaluate(InsightContext $c): ?Insight
    {
        $n = Appointment::query()
            ->forDate($c->now->toDateString())
            ->where('status', AppointmentStatus::NoShow->value)
            ->count();

        if ($n === 0) {
            return null;
        }

        return new Insight(
            $this->key(), 'info',
            self::plural($n, 'no-show').' today',
            'These patients did not come. Rebook them while the slot is fresh in their mind.',
            'admin.appointments.index', 'See appointments', $n, 40,
        );
    }
}
