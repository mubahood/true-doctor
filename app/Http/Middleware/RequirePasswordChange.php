<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces a password change before anything else is reachable, for accounts
 * created with a random temporary password (HMS_PLAN.md constraint C14).
 * Set on User::password_change_required, cleared by PasswordController once
 * the user sets their own password.
 */
class RequirePasswordChange
{
    private const EXEMPT_ROUTES = ['password.change', 'password.update', 'admin.logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->password_change_required && ! $request->routeIs(...self::EXEMPT_ROUTES)) {
            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
