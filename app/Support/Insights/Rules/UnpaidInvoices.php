<?php

namespace App\Support\Insights\Rules;

use App\Support\HospitalSettings;
use App\Support\Insights\Insight;
use App\Support\Insights\InsightContext;
use App\Support\Insights\Rule;

/** Invoices issued and not (fully) paid. Quiet once the overdue rule is already shouting. */
final class UnpaidInvoices extends Rule
{
    public function key(): string
    {
        return 'invoices-unpaid';
    }

    protected function permission(): ?string
    {
        return 'billing.view';
    }

    public function evaluate(InsightContext $c): ?Insight
    {
        $r = Receivables::of($c);
        if ($r['count'] === 0 || bccomp(Receivables::overdue($c), '0', 2) > 0) {
            return null;
        }

        return new Insight(
            $this->key(), 'warn',
            self::plural($r['count'], 'unpaid invoice'),
            HospitalSettings::money($r['total']).' waiting to be collected.',
            'admin.invoices.index', 'See unpaid invoices', $r['count'], 65,
        );
    }
}
