<?php

namespace App\Support\Insights\Rules;

use App\Support\HospitalSettings;
use App\Support\Insights\Insight;
use App\Support\Insights\InsightContext;
use App\Support\Insights\Rule;

/** Nothing left, or at or below the reorder level. */
final class StockRunningOut extends Rule
{
    public function key(): string
    {
        return 'stock-running-out';
    }

    protected function permission(): string
    {
        return 'pharmacy.view';
    }

    protected function module(): string
    {
        return 'pharmacy';
    }

    public function evaluate(InsightContext $c): ?Insight
    {
        $risk = StockRisk::of($c);
        $total = $risk['out'] + $risk['low'];
        if ($total === 0) {
            return null;
        }

        $detail = $risk['out'] > 0
            ? self::plural($risk['out'], 'product').' out of stock'.($risk['low'] > 0 ? ', '.$risk['low'].' running low' : '').'.'
            : self::plural($risk['low'], 'product').' at or below the reorder level.';

        if (bccomp($risk['shortfall'], '0', 2) > 0) {
            $detail .= ' About '.HospitalSettings::money($risk['shortfall']).' to bring them back to the reorder level.';
        }

        return new Insight($this->key(), $risk['out'] > 0 ? 'danger' : 'warn', 'Stock is running out', $detail, 'admin.stock.alerts', 'See what to reorder', $total, 80);
    }
}
