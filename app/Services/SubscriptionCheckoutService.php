<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Models\GatewayLog;
use App\Models\Hospital;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Gateway\GatewayCharge;
use App\Services\Gateway\PaymentGateway;
use App\Support\PlatformCurrency;
use Illuminate\Support\Carbon;

/**
 * Paying for a subscription via the gateway (Pesapal — see the contextual
 * binding in AppServiceProvider). initialize() opens a hosted payment for a
 * plan and logs it as a subscription purpose; activate() (called from the
 * verified, idempotent GatewayPaymentService::settle) converts the hospital's
 * subscription to an active paid period and records a SubscriptionPayment.
 *
 * Plans are priced per month in UGX — the currency Pesapal settles in — so the
 * amount quoted, the amount logged, the amount charged and the amount on the
 * receipt are all the same number. A hospital buys a whole number of months at
 * checkout; that count rides along in the log's meta so activate() extends by
 * exactly what was paid for.
 */
class SubscriptionCheckoutService
{
    /** The longest run a hospital may buy in one go. */
    public const MAX_MONTHS = 24;

    public function __construct(private readonly PaymentGateway $gateway) {}

    /** @param array<string,string|null> $customer @return array{0:GatewayLog,1:GatewayCharge} */
    public function initialize(Hospital $hospital, Plan $plan, string $redirectUrl, array $customer, int $months = 1): array
    {
        $months = max(1, min(self::MAX_MONTHS, $months));

        // Pesapal's merchant reference is capped at 50 characters.
        $txRef = 'SUB'.$hospital->id.'-'.bin2hex(random_bytes(8));
        $amount = PlatformCurrency::forMonths((string) $plan->price, $months);

        $log = GatewayLog::create([
            'hospital_id' => $hospital->id,
            'provider' => 'pesapal',
            'tx_ref' => $txRef,
            'status' => 'pending',
            'amount' => $amount,
            'currency' => PlatformCurrency::CHARGE,
            'meta' => [
                'purpose' => 'subscription',
                'plan_id' => $plan->id,
                'months' => $months,
                'monthly_price' => (string) $plan->price,
            ],
        ]);

        $charge = $this->gateway->initialize([
            'tx_ref' => $txRef,
            'amount' => $amount,
            'currency' => PlatformCurrency::CHARGE,
            'redirect_url' => $redirectUrl,
            'customer' => $customer,
            'meta' => [
                'purpose' => 'subscription',
                'plan' => $plan->slug,
                'description' => "{$plan->name} plan · {$months} ".($months === 1 ? 'month' : 'months')." — {$hospital->name}",
            ],
        ]);

        if (! $charge->ok) {
            $log->update(['status' => 'failed', 'meta' => array_merge((array) $log->meta, ['error' => $charge->error])]);
        }

        return [$log, $charge];
    }

    /**
     * Activate the hospital's subscription for the period just paid for
     * (idempotent via the caller's log lock). A hospital renewing before its
     * current period runs out keeps the time it has already paid for — the new
     * months are added to the end, not from today.
     */
    public function activate(GatewayLog $log): void
    {
        $planId = $log->meta['plan_id'] ?? null;
        $plan = $planId ? Plan::find($planId) : null;
        if ($plan === null) {
            return;
        }

        $months = max(1, (int) ($log->meta['months'] ?? 1));
        $now = Carbon::now();

        $subscription = Subscription::withoutGlobalScopes()
            ->where('hospital_id', $log->hospital_id)
            ->latest('starts_at')
            ->first();

        if ($subscription === null) {
            $subscription = new Subscription(['hospital_id' => $log->hospital_id]);
        }

        // Renewing the same plan early adds to the end of the period already PAID for.
        // A trial is not paid time: converting from one starts the paid period today,
        // rather than handing out the unused trial days on top of it.
        $extendsPaidPeriod = $subscription->exists
            && $subscription->status === SubscriptionStatus::Active
            && $subscription->plan_id === $plan->id
            && $subscription->ends_at?->isFuture();

        $base = $extendsPaidPeriod ? $subscription->ends_at->copy() : $now->copy();

        $subscription->fill([
            'plan_id' => $plan->id,
            'starts_at' => $subscription->exists && $subscription->starts_at ? $subscription->starts_at : $now,
            'ends_at' => $base->addMonths($months),
            'trial_ends_at' => null,
            'status' => SubscriptionStatus::Active,
        ])->save();

        $subscription->payments()->create([
            'amount' => (string) $log->amount,
            'method' => 'pesapal',
            'reference' => $log->tx_ref,
            'notes' => $months.' '.($months === 1 ? 'month' : 'months'),
            'paid_at' => $now,
        ]);

        // Receipt + confirmation to the hospital's admins (webhook has no session).
        $admins = \App\Models\User::where('hospital_id', $log->hospital_id)->where('role', 'hospital_admin')->get();
        foreach ($admins as $admin) {
            $admin->notify(new \App\Notifications\SubscriptionActivated($subscription, $plan, (string) $log->amount));
        }
    }
}
