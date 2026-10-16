<?php

namespace App\Support\Insights\Rules;

use App\Support\Edition;
use App\Support\Insights\Insight;
use App\Support\Insights\InsightContext;
use App\Support\Insights\Rule;
use App\Support\SubscriptionState;

/** SaaS: the free trial (or a paid period) is about to end. */
final class TrialEnding extends Rule
{
    public const DAYS = 7;

    public function key(): string
    {
        return 'subscription-ending';
    }

    protected function permission(): string
    {
        return 'manage-settings';
    }

    protected function edition(): string
    {
        return Edition::SAAS;
    }

    public function evaluate(InsightContext $c): ?Insight
    {
        $state = app(SubscriptionState::class);
        $subscription = $state->current($c->hospital);
        $days = $state->daysRemaining($c->hospital);

        if ($subscription === null || $days === null || $days > self::DAYS) {
            return null;
        }

        $trial = $subscription->status === \App\Enums\SubscriptionStatus::Trialing;
        $ended = $days <= 0;

        return new Insight(
            $this->key(), $days <= 2 ? 'danger' : 'warn',
            $ended
                ? ($trial ? 'Your free trial has ended' : 'Your subscription has ended')
                : ($trial ? 'Your free trial ends in ' : 'Your subscription ends in ').self::plural($days, 'day'),
            $trial ? 'Choose a plan to keep your hospital running without interruption.' : 'Renew to keep your hospital running without interruption.',
            'admin.subscription.index', $trial ? 'Choose a plan' : 'Renew', max(0, $days), 95,
        );
    }
}
