<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticated HTML must never be served from the browser's back/forward
 * cache or Livewire's navigate cache after sign-out. Applied to the admin and
 * super groups (docs/PJAX_LIVEWIRE_IMPROVEMENT_PLAN.md B7).
 */
class NoStoreResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response->headers->has('Cache-Control') || str_contains((string) $response->headers->get('Cache-Control'), 'no-cache')) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }
}
