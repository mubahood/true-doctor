<?php

namespace App\Support\Insights\Rules;

use App\Services\StockAlertBoard;
use App\Support\Insights\Insight;
use App\Support\Insights\InsightContext;
use App\Support\Insights\Rule;

/** Still usable, but inside the expiry horizon: use it first. */
final class StockExpiring extends Rule
{
    public function key(): string
    {
        return 'stock-expiring';
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
        $n = StockRisk::of($c)['expiring'];
        if ($n === 0) {
            return null;
        }

        return new Insight(
            $this->key(), 'warn',
            self::plural($n, 'product').' expiring soon',
            'Expires within '.StockAlertBoard::EXPIRY_HORIZON_DAYS.' days. Dispense these first.',
            'admin.stock.alerts', 'See expiring stock', $n, 60,
        );
    }
}
