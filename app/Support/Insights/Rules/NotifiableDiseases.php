<?php

namespace App\Support\Insights\Rules;

use App\Services\Hmis\HmisReports;
use App\Support\Insights\Insight;
use App\Support\Insights\InsightContext;
use App\Support\Insights\Rule;

/**
 * A notifiable condition was diagnosed in the last week (roadmap 04 §2): the
 * facility in-charge reports it to the district surveillance focal person.
 */
final class NotifiableDiseases extends Rule
{
    public function key(): string
    {
        return 'notifiable-diseases';
    }

    protected function permission(): string
    {
        return 'reports.view';
    }

    public function evaluate(InsightContext $c): ?Insight
    {
        $cases = app(HmisReports::class)->notifiableSince(now()->subDays(7));
        if ($cases === []) {
            return null;
        }
        $n = array_sum($cases);
        arsort($cases);
        $what = implode(', ', array_map(fn ($label, $count) => "{$label} ({$count})", array_keys($cases), $cases));

        return new Insight(
            $this->key(), 'danger',
            self::plural($n, 'notifiable case').' this week',
            $what.'. Report them to the district surveillance focal person; the weekly 033b form is ready.',
            'admin.reports.hmis', 'Open the 033b form', $n, 95,
        );
    }
}
