<?php

namespace App\Support\Insights\Rules;

use App\Models\RadiologyOrder;
use App\Support\Insights\Insight;
use App\Support\Insights\InsightContext;
use App\Support\Insights\Rule;
use App\Support\RadiologyBench;

/** Imaging orders still owing a report past the room's "old" threshold. */
final class RadiologyWaiting extends Rule
{
    public function key(): string
    {
        return 'radiology-waiting';
    }

    protected function permission(): string
    {
        return 'radiology.view';
    }

    protected function module(): string
    {
        return 'radiology';
    }

    public function evaluate(InsightContext $c): ?Insight
    {
        $n = RadiologyOrder::query()
            ->whereIn('status', RadiologyBench::outstandingStatuses())
            ->where('created_at', '<=', $c->now->copy()->subHours(RadiologyBench::OLD_HOURS))
            ->count();

        if ($n === 0) {
            return null;
        }

        return new Insight(
            $this->key(), 'warn',
            self::plural($n, 'imaging order').' waiting over '.RadiologyBench::OLD_HOURS.' hours',
            'Still without a report.',
            'admin.radiology-orders.index', 'Open the imaging worklist', $n, 67,
        );
    }
}
