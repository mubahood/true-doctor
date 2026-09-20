<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\SubscriptionCheckoutService;
use App\Support\HospitalSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Subscription checkout. The page itself is Livewire (Subscription\Index) and
 * its wizard modal collects/confirms the billing phone in-place; this final
 * step stays a classic POST because it ends in an external Pesapal redirect
 * (SubscriptionCheckoutService + the shared gateway settle/callback/IPN).
 */
class SubscriptionCheckoutController extends Controller
{
    public function __construct(private readonly SubscriptionCheckoutService $service) {}

    public function checkout(Request $request, Plan $plan): RedirectResponse
    {
        abort_unless($request->user()?->can('manage-settings'), 403);
        abort_unless($plan->is_active, 404);

        $hospital = app(HospitalSettings::class)->hospital();
        abort_if($hospital === null, 404);

        $contact = app(\App\Support\OnboardingStatus::class)->contact($hospital);

        $validated = $request->validate([
            'months' => ['nullable', 'integer', 'min:1', 'max:'.SubscriptionCheckoutService::MAX_MONTHS],
        ]);
        $months = (int) ($validated['months'] ?? 1);

        [, $charge] = $this->service->initialize(
            $hospital,
            $plan,
            route('gateway.pesapal.callback'),
            [
                'email' => $request->user()->email,
                'name' => $request->user()->name,
                'phone' => $request->string('phone')->trim()->value() ?: ($contact['phone'] ?? null),
                'hospital' => $hospital->name,
            ],
            max(1, $months),
        );

        if (! $charge->ok || $charge->link === null) {
            return back()->with('error', $charge->error ?? 'Could not start the payment.');
        }

        return redirect()->away($charge->link);
    }
}
