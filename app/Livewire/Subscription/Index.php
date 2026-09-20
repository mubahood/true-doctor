<?php

namespace App\Livewire\Subscription;

use App\Models\Plan;
use App\Models\Subscription;
use App\Support\HospitalSettings;
use App\Support\OnboardingStatus;
use App\Support\SubscriptionState;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The hospital owner's subscription page: current status (with a clear,
 * unmissable expiry/blocked state — App\Http\Middleware\EnsureSubscribed
 * enforces the same rule this page explains), payment history, and a small
 * wizard for subscribing/renewing. The wizard's own steps are pure Livewire;
 * only its final "Pay" step is a real `<form method="post">` — that one
 * legitimately leaves the SPA for Pesapal's hosted page (plan §3.1), and
 * SpaNavigationHtmlTest allow-lists exactly that route for exactly this
 * reason.
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    public bool $showWizard = false;

    /** The plan the wizard is open for. */
    #[Locked]
    public ?int $wizardPlanId = null;

    /** 1 = choose how long and review, 2 = confirm billing details and pay. */
    public int $wizardStep = 1;

    /** How many months the hospital is buying — the total updates as this changes. */
    public int $months = 1;

    public string $phone = '';

    public function mount(): void
    {
        $this->authorizeView();
    }

    private function authorizeView(): void
    {
        abort_unless(Auth::user()?->can('manage-settings'), 403);
    }

    private function hospital(): ?\App\Models\Hospital
    {
        return app(HospitalSettings::class)->hospital();
    }

    private function state(): SubscriptionState
    {
        return app(SubscriptionState::class);
    }

    #[Computed]
    public function subscription(): ?Subscription
    {
        return $this->state()->current($this->hospital());
    }

    /** Payment history for the current subscription — always shown once one exists, even if empty. */
    #[Computed]
    public function payments()
    {
        return $this->subscription()?->payments()->latest('paid_at')->get() ?? collect();
    }

    #[Computed]
    public function plans()
    {
        return Plan::where('is_active', true)->orderBy('price')->get();
    }

    #[Computed]
    public function wizardPlan(): ?Plan
    {
        return $this->wizardPlanId ? Plan::find($this->wizardPlanId) : null;
    }

    /** Whether the subscription is currently blocking access — the same question EnsureSubscribed asks. */
    #[Computed]
    public function isBlocked(): bool
    {
        return ! $this->state()->grantsAccess($this->hospital());
    }

    #[Computed]
    public function daysRemaining(): ?int
    {
        return $this->state()->daysRemaining($this->hospital());
    }

    /**
     * A trial is not a plan the hospital is on — it has bought nothing yet, so
     * no card is marked "Current plan" and every one of them stays buyable.
     */
    #[Computed]
    public function currentPlanId(): ?int
    {
        return $this->state()->paidPlanId($this->hospital());
    }

    /** Whole months a hospital can buy in one go. */
    #[Computed]
    public function monthOptions(): array
    {
        return [1, 3, 6, 12, 24];
    }

    /** What the hospital will actually be charged for the months it picked. */
    #[Computed]
    public function wizardTotal(): string
    {
        $plan = $this->wizardPlan();

        return $plan ? \App\Support\PlatformCurrency::forMonths((string) $plan->price, $this->months) : '0.00';
    }

    /** The date the paid period would run to, so the choice is concrete, not arithmetic. */
    #[Computed]
    public function wizardCoversUntil(): string
    {
        $sub = $this->subscription();
        $from = ($sub?->ends_at?->isFuture() && $sub->plan_id === $this->wizardPlanId) ? $sub->ends_at->copy() : now();

        return $from->addMonths(max(1, $this->months))->format('d M Y');
    }

    public function openWizard(int $planId): void
    {
        $this->authorizeView();

        $this->wizardPlanId = $planId;
        $this->wizardStep = 1;
        $this->months = 1;
        $this->showWizard = true;

        $hospital = $this->hospital();
        $contact = $hospital ? app(OnboardingStatus::class)->contact($hospital) : [];
        $this->phone = (string) ($contact['phone'] ?? '');
    }

    /** Keep the months inside what checkout will accept, whatever arrives from the client. */
    public function updatedMonths(): void
    {
        $this->months = max(1, min(\App\Services\SubscriptionCheckoutService::MAX_MONTHS, $this->months));
        unset($this->wizardTotal, $this->wizardCoversUntil);
    }

    public function wizardNext(): void
    {
        $this->wizardStep = min(2, $this->wizardStep + 1);
    }

    public function wizardBack(): void
    {
        $this->wizardStep = max(1, $this->wizardStep - 1);
    }

    public function render()
    {
        $this->authorizeView();

        return view('livewire.subscription.index')->title('Subscription');
    }
}
