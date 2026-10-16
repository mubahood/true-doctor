<?php

namespace App\Support\Insights\Rules;

use App\Enums\ClaimStatus;
use App\Models\InsuranceClaim;
use App\Support\Insights\Insight;
use App\Support\Insights\InsightContext;
use App\Support\Insights\Rule;

/**
 * Claims somebody must act on: drafts to submit, approved claims to collect,
 * and submitted claims the insurer has sat on for too long.
 */
final class ClaimsAwaitingAction extends Rule
{
    public const SUBMITTED_STALE_DAYS = 14;

    public function key(): string
    {
        return 'claims-awaiting-action';
    }

    protected function permission(): string
    {
        return 'insurance.view';
    }

    protected function module(): string
    {
        return 'insurance';
    }

    public function evaluate(InsightContext $c): ?Insight
    {
        $counts = InsuranceClaim::query()
            ->whereIn('status', [ClaimStatus::Draft->value, ClaimStatus::Submitted->value, ClaimStatus::Approved->value])
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $stale = InsuranceClaim::query()
            ->where('status', ClaimStatus::Submitted->value)
            ->where('submitted_at', '<=', $c->now->copy()->subDays(self::SUBMITTED_STALE_DAYS))
            ->count();

        $draft = (int) ($counts[ClaimStatus::Draft->value] ?? 0);
        $approved = (int) ($counts[ClaimStatus::Approved->value] ?? 0);
        $total = $draft + $approved + $stale;
        if ($total === 0) {
            return null;
        }

        $parts = array_filter([
            $draft > 0 ? "{$draft} to submit" : null,
            $approved > 0 ? "{$approved} approved and awaiting payment" : null,
            $stale > 0 ? "{$stale} submitted over ".self::SUBMITTED_STALE_DAYS.' days ago' : null,
        ]);

        return new Insight(
            $this->key(), $stale > 0 ? 'warn' : 'info',
            self::plural($total, 'insurance claim').' need action',
            ucfirst(implode(', ', $parts)).'.',
            'admin.insurance-claims.index', 'Open claims', $total, 62,
        );
    }
}
