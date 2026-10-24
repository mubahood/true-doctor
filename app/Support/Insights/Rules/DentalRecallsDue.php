<?php

namespace App\Support\Insights\Rules;

use App\Enums\AppointmentStatus;
use App\Models\Patient;
use App\Support\Insights\Insight;
use App\Support\Insights\InsightContext;
use App\Support\Insights\Rule;

/**
 * Clinical follow-up: dental check-ups that fell due in the last 60 days for
 * patients who have neither been back nor booked. The recall text (if on)
 * went once; this is the list to ring.
 */
final class DentalRecallsDue extends Rule
{
    public const DAYS = 60;

    public function key(): string
    {
        return 'dental-recalls-due';
    }

    protected function permission(): string
    {
        return 'appointments.manage';
    }

    public function applies(InsightContext $context): bool
    {
        return in_array('dental', $context->practice->packKeys(), true) && parent::applies($context);
    }

    public function evaluate(InsightContext $c): ?Insight
    {
        $today = $c->now->toDateString();

        $n = Patient::query()
            ->whereNotNull('next_recall_on')
            ->whereDate('next_recall_on', '<=', $today)
            ->whereDate('next_recall_on', '>=', $c->now->copy()->subDays(self::DAYS)->toDateString())
            // Not back since it fell due...
            ->whereNotExists(fn ($q) => $q->from('visits')
                ->whereColumn('visits.patient_id', 'patients.id')
                ->whereColumn('visits.created_at', '>=', 'patients.next_recall_on'))
            // ...and nothing booked from today on.
            ->whereNotExists(fn ($q) => $q->from('appointments')
                ->whereColumn('appointments.patient_id', 'patients.id')
                ->where('appointments.scheduled_at', '>=', $c->now->copy()->startOfDay())
                ->whereNotIn('appointments.status', [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value]))
            ->count();

        if ($n === 0) {
            return null;
        }

        return new Insight(
            $this->key(), 'info',
            self::plural($n, 'dental check-up').' overdue',
            'Due in the last '.self::DAYS.' days, not back and not booked. A call now fills next week\'s chairs.',
            'admin.appointments.recalls', 'Call them', $n, 45,
        );
    }
}
