<?php

namespace App\Support\Insights\Rules;

use App\Enums\VisitStatus;
use App\Models\Visit;
use App\Support\Insights\Insight;
use App\Support\Insights\InsightContext;
use App\Support\Insights\Rule;

/** Visits opened days ago and never completed or cancelled. A doctor is told only about their own. */
final class VisitsStuckOpen extends Rule
{
    public const DAYS = 3;

    public function key(): string
    {
        return 'visits-stuck-open';
    }

    protected function permission(): string
    {
        return 'visits.view';
    }

    public function evaluate(InsightContext $c): ?Insight
    {
        $n = Visit::query()
            ->whereIn('status', [VisitStatus::Pending->value, VisitStatus::Ongoing->value])
            ->where('created_at', '<=', $c->now->copy()->subDays(self::DAYS))
            ->when($c->user->role === 'doctor', fn ($q) => $q->where('doctor_user_id', $c->user->id))
            ->count();

        if ($n === 0) {
            return null;
        }

        return new Insight(
            $this->key(), 'warn',
            self::plural($n, 'visit').' open for over '.self::DAYS.' days',
            'Complete or cancel them so the day\'s figures and bills are right.',
            'admin.visits.index', 'Review open visits', $n, 55,
        );
    }
}
