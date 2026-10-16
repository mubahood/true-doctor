<?php

namespace App\Support\Insights\Rules;

use App\Support\HospitalSettings;
use App\Support\Insights\Insight;
use App\Support\Insights\InsightContext;
use App\Support\Insights\Rule;

/** Expired stock still on the books: to write off, not to dispense. */
final class StockExpired extends Rule
{
    public function key(): string
    {
        return 'stock-expired';
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
        if ($risk['expired'] === 0) {
            return null;
        }

        return new Insight(
            $this->key(), 'danger',
            self::plural($risk['expired'], 'expired product').' still in stock',
            'Worth '.HospitalSettings::money($risk['value']).' on the books. Write it off so it cannot be dispensed.',
            'admin.stock.alerts', 'Review expired stock', $risk['expired'], 90,
        );
    }
}
