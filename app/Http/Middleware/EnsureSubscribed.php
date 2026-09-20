<?php

namespace App\Http\Middleware;

use App\Models\Hospital;
use App\Support\CurrentHospital;
use App\Support\SubscriptionState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks tenant routes once a hospital's subscription has lapsed, with a
 * configurable grace period (HMS_PLAN.md §2.1, config/tenancy.php). Must run
 * after ResolveHospital. No-op outside a hospital context (super-admin
 * browsing centrally, or a route this middleware isn't applied to).
 *
 * Deliberately narrow, the same way RequireOnboarding is: the page that fixes
 * the block (the subscription page, and the checkout it posts to) must stay
 * reachable — a hospital that cannot pay must still be able to reach the page
 * that lets it pay. Blocking used to abort(403) unconditionally, which meant
 * a lapsed hospital could not even reach /admin/subscription to renew.
 */
class EnsureSubscribed
{
    private const ALLOWED = [
        'admin.subscription.*', 'admin.logout',
    ];

    public function __construct(private readonly SubscriptionState $state) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('tenancy.enforce_subscription', true)) {
            return $next($request);
        }

        $hospitalId = app(CurrentHospital::class)->id();

        if ($hospitalId === null) {
            return $next($request);
        }

        if ($request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        // Whether the hospital may be here at all is SubscriptionState's
        // question to answer — the same object the subscription page and the
        // header badge read, so none of the three can drift from the others.
        if (! $this->state->grantsAccess(Hospital::find($hospitalId))) {
            return $this->blocked($request);
        }

        return $next($request);
    }

    /**
     * The admin who can actually fix this is sent straight to the page that
     * fixes it, with a clear reason. Anyone else — who cannot subscribe or pay
     * — gets a plain, on-brand explanation rather than a raw framework 403.
     */
    private function blocked(Request $request): Response
    {
        $message = "Your hospital's subscription has ended.";

        if (! $request->isMethod('get') || $request->ajax() || $request->wantsJson() || $request->hasHeader('X-Livewire')) {
            abort(403, $message);
        }

        if ($request->user()?->can('manage-settings')) {
            return redirect()
                ->route('admin.subscription.index')
                ->with('warning', $message.' Renew below to get back in.');
        }

        return response()->view('errors.subscription-lapsed', [], 403);
    }
}
