<?php

namespace App\Http\Middleware;

use App\Support\CurrentHospital;
use App\Support\Edition;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant context for this request into the CurrentHospital
 * singleton, before any tenant-scoped query runs (HMS_PLAN.md §2.1).
 *
 * A hospital-scoped staff member is always scoped to their own hospital.
 * A super-admin (`hospital_id` null) has no scope by default — seeing
 * across every hospital — unless they've switched into one via the
 * super-admin panel (Phase 0 Step 5), tracked in the session.
 */
class ResolveHospital
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $hospitalId = $user?->hospital_id;

        // A central SaaS account has no tenant filter. It must never acquire
        // unscoped access on a standalone server, even via an old API token.
        if (Edition::isStandalone() && $user !== null && $hospitalId === null) {
            abort(403, 'This account does not belong to this organisation.');
        }

        // Super-admin (null hospital_id) may switch into a hospital via the
        // panel, tracked in the session. Guard on hasSession(): the stateless
        // API group has no session, and reading it there would 500.
        if (Edition::isSaas() && $user !== null && $hospitalId === null && $request->hasSession()) {
            $hospitalId = $request->session()->get('viewing_hospital_id');
        }

        app(CurrentHospital::class)->set($hospitalId);

        return $next($request);
    }
}
