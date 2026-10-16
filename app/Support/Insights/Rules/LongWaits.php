<?php

namespace App\Support\Insights\Rules;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Support\CheckInQueue;
use App\Support\Insights\Insight;
use App\Support\Insights\InsightContext;
use App\Support\Insights\Rule;

/** Checked in and still waiting past the queue board's own thresholds. */
final class LongWaits extends Rule
{
    public function key(): string
    {
        return 'queue-long-waits';
    }

    protected function permission(): string
    {
        return 'appointments.view';
    }

    public function evaluate(InsightContext $c): ?Insight
    {
        $waiting = fn () => Appointment::query()
            ->forDate($c->now->toDateString())
            ->where('status', AppointmentStatus::CheckedIn->value);

        $warn = $waiting()->where('checked_in_at', '<=', $c->now->copy()->subMinutes(CheckInQueue::WAIT_WARN))->count();
        if ($warn === 0) {
            return null;
        }
        $bad = $waiting()->where('checked_in_at', '<=', $c->now->copy()->subMinutes(CheckInQueue::WAIT_BAD))->count();

        return new Insight(
            $this->key(), $bad > 0 ? 'danger' : 'warn',
            self::plural($warn, 'patient').' waiting too long',
            $bad > 0
                ? "{$bad} ".($bad === 1 ? 'has' : 'have').' waited over '.CheckInQueue::WAIT_BAD.' minutes.'
                : 'Waiting over '.CheckInQueue::WAIT_WARN.' minutes since check-in.',
            'admin.appointments.queue', 'Open the queue', $warn, 85,
        );
    }
}
