<?php

namespace App\Support\Insights\Rules;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Support\HospitalSettings;
use App\Support\Insights\Insight;
use App\Support\Insights\InsightContext;
use App\Support\Insights\Rule;

/**
 * Money leak: bills of the last 7 days discounted by more than a fifth.
 * A discount is allowed; a run of large ones is worth the owner's eye.
 * Shown to whoever reads the reports (the owner), not to the cashier who gave it.
 */
final class LargeDiscounts extends Rule
{
    public const DAYS = 7;

    /** A discount above this share of the bill, in percent, counts as large. */
    public const SHARE = 20;

    public function key(): string
    {
        return 'discounts-large';
    }

    protected function permission(): string
    {
        return 'reports.view';
    }

    public function evaluate(InsightContext $c): ?Insight
    {
        $row = Invoice::query()
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Void->value])
            ->where('created_at', '>=', $c->now->copy()->subDays(self::DAYS))
            ->where('discount', '>', 0)
            // discount / subtotal > SHARE / 100, without dividing.
            ->whereRaw('discount * 100 > subtotal * ?', [self::SHARE])
            ->selectRaw('count(*) as n, coalesce(sum(discount), 0) as total')
            ->toBase()->first();

        $n = (int) ($row->n ?? 0);
        if ($n === 0) {
            return null;
        }

        return new Insight(
            $this->key(), 'warn',
            self::plural($n, 'large discount'),
            HospitalSettings::money((string) $row->total).' given off bills in the last '.self::DAYS.' days, each over '.self::SHARE.'% of the bill.',
            'admin.invoices.index', 'Check the invoices', $n, 62,
        );
    }
}
