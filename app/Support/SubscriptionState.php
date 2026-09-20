<?php

namespace App\Support;

use App\Enums\SubscriptionStatus;
use App\Models\Hospital;
use App\Models\Subscription;
use App\Support\Subscription\Badge;

/**
 * Single source of truth for where a hospital stands with its subscription —
 * read by the gate (App\Http\Middleware\EnsureSubscribed), the subscription
 * page (App\Livewire\Subscription\Index) and the header badge, so what the
 * admin is told and what the system enforces can never drift apart.
 *
 * Nothing is stored: every answer is derived from the hospital's real
 * subscription rows, so a payment that lands mid-session is reflected on the
 * very next page.
 */
class SubscriptionState
{
    /**
     * The subscription that counts: the most recent one whose status grants
     * access, falling back to the latest row so an expired hospital still sees
     * what it used to have. Never an arbitrary "latest", which would let a
     * later cancelled row mask a still-valid one.
     */
    public function current(?Hospital $hospital): ?Subscription
    {
        if ($hospital === null) {
            return null;
        }

        $subscriptions = $hospital->subscriptions()->get();

        return $subscriptions
            ->filter(fn (Subscription $s) => $s->status->grantsAccess())
            ->sortByDesc('starts_at')
            ->first()
            ?? $subscriptions->sortByDesc('starts_at')->first();
    }

    /** Whether the hospital may use the system right now, grace period included. */
    public function grantsAccess(?Hospital $hospital): bool
    {
        $subscription = $this->current($hospital);

        if ($subscription === null || ! $subscription->status->grantsAccess()) {
            return false;
        }

        if ($subscription->ends_at === null) {
            return true;
        }

        return now()->lessThanOrEqualTo($subscription->ends_at->copy()->addDays($this->graceDays()));
    }

    /**
     * Days left before the current period ends; negative once it has passed.
     * Rounded up, because a trial ending in 9 days and 23 hours reads as "10
     * days left" to everyone except a floor() — the last day still counts.
     */
    public function daysRemaining(?Hospital $hospital): ?int
    {
        $subscription = $this->current($hospital);
        $until = $subscription?->status === SubscriptionStatus::Trialing
            ? $subscription->trial_ends_at
            : $subscription?->ends_at;

        return $until ? (int) ceil(now()->diffInHours($until, false) / 24) : null;
    }

    /** A trial has bought nothing: no plan is "current" until one is actually paid for. */
    public function paidPlanId(?Hospital $hospital): ?int
    {
        $subscription = $this->current($hospital);

        return ($subscription?->status === SubscriptionStatus::Active && $this->grantsAccess($hospital))
            ? $subscription->plan_id
            : null;
    }

    /**
     * What the header should say about all this — or null when there is
     * nothing worth a badge (no hospital context, e.g. a super-admin).
     */
    public function badge(?Hospital $hospital): ?Badge
    {
        if ($hospital === null) {
            return null;
        }

        $subscription = $this->current($hospital);
        $days = $this->daysRemaining($hospital);
        $blocked = ! $this->grantsAccess($hospital);

        // Never subscribed, never trialed — the whole point is to start.
        if ($subscription === null) {
            return new Badge('Subscribe now', 'primary', 'Pick a plan to get your hospital running.', urgent: true);
        }

        if ($blocked) {
            return $subscription->status === SubscriptionStatus::Trialing
                ? new Badge('Trial ended', 'danger', 'Your free trial is over — subscribe to get back in.', urgent: true)
                : new Badge('Subscription ended', 'danger', 'Renew to get your hospital back online.', urgent: true);
        }

        if ($subscription->status === SubscriptionStatus::Trialing) {
            $left = $this->plural($days);

            return $days !== null && $days <= 7
                ? new Badge("Trial ends in {$left}", 'danger', 'Subscribe now so nothing stops when the trial does.', urgent: true)
                : new Badge("Trial · {$left} left", 'warn', 'Enjoying it? Pick a plan any time — you keep everything you have set up.');
        }

        // Paid and healthy. Quiet until renewal is close enough to matter.
        if ($days === null) {
            return new Badge('Subscribed', 'ok', 'Your subscription is active.');
        }

        return $days <= 14
            ? new Badge('Renew · '.$this->plural($days).' left', 'warn', 'Renew now and the new months are added to the end of this period.', urgent: true)
            : new Badge($this->plural($days).' left', 'ok', 'Your subscription is active.');
    }

    private function plural(?int $days): string
    {
        $days = max(0, (int) $days);

        return $days.' '.($days === 1 ? 'day' : 'days');
    }

    private function graceDays(): int
    {
        return (int) config('tenancy.subscription_grace_days', 3);
    }
}
