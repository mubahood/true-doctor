<?php

namespace App\Support\Insights\Rules;

use App\Enums\FinancialYearStatus;
use App\Models\FinancialYear;
use App\Support\Insights\Insight;
use App\Support\Insights\InsightContext;
use App\Support\Insights\Rule;

/** No open financial year covers today, or the open one is about to end. */
final class FinancialYearAttention extends Rule
{
    public const ENDING_DAYS = 30;

    public function key(): string
    {
        return 'financial-year';
    }

    protected function permission(): string
    {
        return 'finance.view';
    }

    public function evaluate(InsightContext $c): ?Insight
    {
        $today = $c->now->toDateString();

        $current = FinancialYear::query()
            ->where('status', FinancialYearStatus::Open->value)
            ->whereDate('starts_on', '<=', $today)
            ->whereDate('ends_on', '>=', $today)
            ->orderBy('ends_on')
            ->first();

        if ($current === null) {
            return new Insight(
                $this->key(), 'warn', 'No open financial year',
                'Nothing covers today, so closing periods and yearly statements have no year to work on.',
                'admin.financial-years.index', 'Set up the financial year', null, 52,
            );
        }

        $days = (int) $c->now->copy()->startOfDay()->diffInDays($current->ends_on->copy()->startOfDay(), false);
        if ($days > self::ENDING_DAYS) {
            return null;
        }

        return new Insight(
            $this->key(), 'info', "{$current->name} ends in ".self::plural(max(0, $days), 'day'),
            'Create the next financial year before this one closes.',
            'admin.financial-years.index', 'Open financial years', max(0, $days), 45,
        );
    }
}
