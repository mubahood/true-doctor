<?php

namespace App\Support\Insights\Rules;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Support\Insights\Insight;
use App\Support\Insights\InsightContext;
use App\Support\Insights\Rule;

/** Booked for earlier today and not checked in yet. */
final class ExpectedNotArrived extends Rule
{
    /** Minutes past the appointment time before it counts as late. */
    public const GRACE_MINUTES = 15;

    public function key(): string
    {
        return 'appointments-not-arrived';
    }

    protected function permission(): string
    {
        return 'appointments.view';
    }

    public function evaluate(InsightContext $c): ?Insight
    {
        $n = Appointment::query()
            ->forDate($c->now->toDateString())
            ->whereIn('status', [AppointmentStatus::Scheduled->value, AppointmentStatus::Confirmed->value])
            ->where('scheduled_at', '<=', $c->now->copy()->subMinutes(self::GRACE_MINUTES))
            ->count();

        if ($n === 0) {
            return null;
        }

        return new Insight(
            $this->key(), 'warn',
            self::plural($n, 'appointment').' not checked in',
            'Booked for earlier today and the patient has not arrived. Check in, call, or mark a no-show.',
            'admin.appointments.queue', 'See who is expected', $n, 70,
        );
    }
}
