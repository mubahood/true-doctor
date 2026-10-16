<?php

namespace App\Support\Insights\Rules;

use App\Support\HospitalSettings;
use App\Support\Insights\Insight;
use App\Support\Insights\InsightContext;
use App\Support\Insights\Rule;

/** Money owed for more than 30 days (the aging report's older buckets). */
final class OverdueInvoices extends Rule
{
    public function key(): string
    {
        return 'invoices-overdue';
    }

    protected function permission(): ?string
    {
        return 'billing.view';
    }

    public function evaluate(InsightContext $c): ?Insight
    {
        $overdue = Receivables::overdue($c);
        if (bccomp($overdue, '0', 2) <= 0) {
            return null;
        }

        $b = Receivables::of($c)['buckets'];

        return new Insight(
            $this->key(), 'danger',
            HospitalSettings::money($overdue).' overdue',
            'Unpaid for over 30 days'.(bccomp($b['Over 90 days'], '0', 2) > 0 ? ', '.HospitalSettings::money($b['Over 90 days']).' of it over 90 days' : '').'.',
            'admin.invoices.index', 'Chase unpaid invoices', null, 88,
        );
    }
}
