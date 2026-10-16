<?php

namespace App\Support\Insights\Rules;

use App\Enums\AdmissionStatus;
use App\Models\Admission;
use App\Support\Insights\Insight;
use App\Support\Insights\InsightContext;
use App\Support\Insights\Rule;

/** Inpatients still in a bed after a long stay: review the plan, or the bill. */
final class LongStayAdmissions extends Rule
{
    public const DAYS = 14;

    public function key(): string
    {
        return 'admissions-long-stay';
    }

    protected function permission(): string
    {
        return 'ipd.view';
    }

    protected function module(): string
    {
        return 'inpatient';
    }

    public function evaluate(InsightContext $c): ?Insight
    {
        $n = Admission::query()
            ->where('status', AdmissionStatus::Admitted->value)
            ->where('admitted_at', '<=', $c->now->copy()->subDays(self::DAYS))
            ->count();

        if ($n === 0) {
            return null;
        }

        return new Insight(
            $this->key(), 'info',
            self::plural($n, 'inpatient').' in bed over '.self::DAYS.' days',
            'Long stays are worth a review: discharge plan, bed charges and the running bill.',
            'admin.admissions.index', 'See admissions', $n, 42,
        );
    }
}
