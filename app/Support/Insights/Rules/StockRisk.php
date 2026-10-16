<?php

namespace App\Support\Insights\Rules;

use App\Services\StockAlertBoard;
use App\Support\Insights\InsightContext;

/** The stock board's figures, asked once per request for the three stock rules. */
final class StockRisk
{
    /** @return array{out:int,low:int,expiring:int,expired:int,value:string,shortfall:string} */
    public static function of(InsightContext $c): array
    {
        return $c->memo('stock-risk', fn () => (new StockAlertBoard)->atRisk());
    }
}
