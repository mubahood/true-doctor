<?php

namespace App\Support\Insights\Rules;

use App\Models\LabOrder;
use App\Support\Insights\Insight;
use App\Support\Insights\InsightContext;
use App\Support\Insights\Rule;
use App\Support\LabBench;

/** Lab orders still owing a result past the bench's own "old" threshold. */
final class LabWaiting extends Rule
{
    public function key(): string
    {
        return 'lab-waiting';
    }

    protected function permission(): ?string
    {
        return 'lab.view';
    }

    protected function module(): ?string
    {
        return 'laboratory';
    }

    public function evaluate(InsightContext $c): ?Insight
    {
        $n = LabOrder::query()
            ->whereIn('status', LabBench::outstandingStatuses())
            ->where('created_at', '<=', $c->now->copy()->subHours(LabBench::OLD_HOURS))
            ->count();

        if ($n === 0) {
            return null;
        }

        return new Insight(
            $this->key(), 'warn',
            self::plural($n, 'lab order').' waiting over '.LabBench::OLD_HOURS.' hours',
            'Still without a result. Patients and doctors are waiting on these.',
            'admin.lab-orders.index', 'Open the lab worklist', $n, 68,
        );
    }
}
