<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Services\SubscriptionCheckoutService;
use App\Support\ApiResponse;
use App\Support\HospitalSettings;
use App\Support\PlatformCurrency;
use App\Support\SubscriptionState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Subscription (SaaS only, manage-settings): where the hospital stands with
 * its plan, the plans on offer, and its payment history — the data of the
 * web's Subscription page, read-only. Paying happens on the web (Pesapal's
 * hosted page): `pay_url` is the page the owner opens to do it. This route
 * stays reachable when the subscription has ended, as the web page does, so
 * the app can show the renewal screen with real figures.
 */
class SubscriptionController extends \App\Http\Controllers\Controller
{
    public function __construct(private readonly SubscriptionState $state) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage-settings'), 403);

        $hospital = app(HospitalSettings::class)->hospital();
        abort_if($hospital === null, 404, 'No hospital in context.');

        $current = $this->state->current($hospital);
        $paidPlanId = $this->state->paidPlanId($hospital);
        $badge = $this->state->badge($hospital);

        $payments = SubscriptionPayment::query()
            ->whereIn('subscription_id', Subscription::query()->pluck('id'))
            ->latest('paid_at')->get();

        return ApiResponse::success([
            'subscription' => [
                'status' => $current?->status->value,
                'status_label' => $current?->status->label(),
                'plan' => $current?->plan ? $this->plan($current->plan, $paidPlanId) : null,
                'starts_at' => $current?->starts_at?->toIso8601String(),
                'ends_at' => $current?->ends_at?->toIso8601String(),
                'trial_ends_at' => $current?->trial_ends_at?->toIso8601String(),
                'days_remaining' => $this->state->daysRemaining($hospital),
                'grants_access' => $this->state->grantsAccess($hospital),
                'is_blocked' => ! $this->state->grantsAccess($hospital),
                'paid_plan_id' => $paidPlanId,
                'badge' => $badge ? ['label' => $badge->label, 'tone' => $badge->tone, 'nudge' => $badge->nudge, 'urgent' => $badge->urgent] : null,
            ],
            'plans' => Plan::where('is_active', true)->orderBy('price')->get()->map(fn (Plan $p) => $this->plan($p, $paidPlanId))->values(),
            'payments' => $payments->map(fn (SubscriptionPayment $p) => [
                'id' => $p->id,
                'paid_at' => $p->paid_at?->toIso8601String(),
                'method' => $p->method,
                'amount' => (string) $p->amount,
                'amount_label' => PlatformCurrency::format($p->amount),
                'reference' => $p->reference,
                'period' => $p->notes,
            ])->values(),
            // The web page where the owner chooses a plan and pays.
            'pay_url' => route('admin.subscription.index'),
            'month_options' => [1, 3, 6, 12, 24],
            'max_months' => SubscriptionCheckoutService::MAX_MONTHS,
        ]);
    }

    /** @return array<string,mixed> */
    private function plan(Plan $plan, ?int $paidPlanId): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'description' => $plan->description,
            'price' => (string) $plan->price,
            'price_label' => PlatformCurrency::format($plan->price),
            'billing_cycle' => $plan->billing_cycle->value,
            'limits' => $plan->limits ?? (object) [],
            'features' => $plan->features ?? [],
            'is_featured' => (bool) $plan->is_featured,
            'is_current' => $paidPlanId === $plan->id,
        ];
    }
}
