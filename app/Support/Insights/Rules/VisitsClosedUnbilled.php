<?php

namespace App\Support\Insights\Rules;

use App\Enums\InvoiceStatus;
use App\Enums\OrderItemStatus;
use App\Enums\VisitStatus;
use App\Models\OrderItem;
use App\Models\Visit;
use App\Support\HospitalSettings;
use App\Support\Insights\Insight;
use App\Support\Insights\InsightContext;
use App\Support\Insights\Rule;

/**
 * Money leak: visits that finished (closed, cancelled or overridden) in the
 * last 30 days with work on them (services, tests, drugs dispensed) and no
 * invoice. The work was done; nobody was asked to pay for it.
 */
final class VisitsClosedUnbilled extends Rule
{
    public const DAYS = 30;

    public function key(): string
    {
        return 'visits-closed-unbilled';
    }

    protected function permission(): string
    {
        return 'billing.view';
    }

    public function evaluate(InsightContext $c): ?Insight
    {
        $visits = Visit::query()
            ->where('status', VisitStatus::Completed->value)
            ->where('completed_at', '>=', $c->now->copy()->subDays(self::DAYS))
            ->whereHas('orderItems', fn ($q) => $q->where('order_items.status', '!=', OrderItemStatus::Cancelled->value))
            ->whereDoesntHave('invoices', fn ($q) => $q->where('status', '!=', InvoiceStatus::Void->value))
            ->select('id');

        $n = (clone $visits)->count();
        if ($n === 0) {
            return null;
        }

        $value = (string) (OrderItem::query()
            ->where('status', '!=', OrderItemStatus::Cancelled->value)
            ->whereHas('order', fn ($q) => $q->whereIn('visit_id', $visits))
            ->sum('line_total') ?: '0');

        return new Insight(
            $this->key(), 'danger',
            self::plural($n, 'visit').' closed without a bill',
            HospitalSettings::money($value).' of work done in the last '.self::DAYS.' days was never invoiced.',
            'admin.visits.index', 'Find the visits', $n, 87,
        );
    }
}
