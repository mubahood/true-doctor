<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the SaaS-central super-admin panel (`/super/*` — HMS_PLAN.md §10
 * convention: "/admin/* tenant back-office, /super/* SaaS central").
 */
class IsSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isSuperAdmin()) {
            abort(403, 'Super Admin access required.');
        }

        return $next($request);
    }
}
