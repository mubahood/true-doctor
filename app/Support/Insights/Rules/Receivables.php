<?php

namespace App\Support\Insights\Rules;

use App\Services\ReportService;
use App\Support\Insights\InsightContext;

/** What is owed and how old it is (ReportService::outstanding), asked once per request. */
final class Receivables
{
    /** @return array{total:string,count:int,buckets:array<string,string>} */
    public static function of(InsightContext $c): array
    {
        return $c->memo('receivables', fn () => app(ReportService::class)->outstanding());
    }

    /** What has been owed for more than 30 days. */
    public static function overdue(InsightContext $c): string
    {
        $b = self::of($c)['buckets'];

        return bcadd($b['31-90 days'], $b['Over 90 days'], 2);
    }
}
