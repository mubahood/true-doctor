<?php

namespace App\Http\Middleware;

use App\Support\OnboardingStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the hospital owner in the setup wizard until the required configuration
 * exists (see OnboardingStatus::REQUIRED). Setup is mandatory: a half-configured
 * hospital cannot bill, schedule or staff itself, and letting it in produces
 * broken invoices and unassignable work rather than a usable system.
 *
 * Deliberately narrow so it can never trap anyone:
 *   - only the admin who can configure (manage-settings); never other staff,
 *     never a super-admin (who has no hospital of their own);
 *   - only plain GET page loads — never POST, AJAX or Livewire updates, so the
 *     wizard's own round-trips and every form submission pass through;
 *   - the wizard, the pages that complete it, and account essentials stay
 *     reachable, so there is no redirect loop and no dead end.
 */
class RequireOnboarding
{
    /**
     * Routes that stay reachable while setup is incomplete: the wizard itself,
     * the module pages each step links to for advanced work, and the handful of
     * account/system pages someone must always be able to reach.
     */
    public const ALLOWED = [
        'admin.onboarding', 'admin.onboarding.*',
        // Pages the required steps link to.
        'admin.settings.*',       // billing, currency, hospital settings
        'admin.services.*',       // price list
        'admin.departments.*',    // departments
        'admin.users.*', 'admin.staff.*', // the team
        // Recommended steps — reachable so they can be done early if wanted.
        'admin.rooms.*', 'admin.wards.*', 'admin.beds.*',
        'admin.lab-tests.*', 'admin.radiology-studies.*', 'admin.stock-categories.*',
        // Always reachable.
        'admin.subscription.*', 'admin.notifications.*', 'admin.logout',
    ];

    public function __construct(private readonly OnboardingStatus $status) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Only gate real page navigations — never form posts, AJAX, or Livewire.
        if (! $request->isMethod('get') || $request->ajax() || $request->wantsJson() || $request->hasHeader('X-Livewire')) {
            return $next($request);
        }

        // Never redirect the wizard or the pages that complete it.
        if ($request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        // Subject to setup, and something required is still missing? The same
        // question the sidebar asks, so the menu only ever offers pages that
        // survive this gate.
        if (! $this->status->mustCompleteSetup($request->user())) {
            return $next($request);
        }

        return redirect()
            ->route('admin.onboarding')
            ->with('warning', 'Finish setting up your hospital to unlock the rest of the system.');
    }
}
