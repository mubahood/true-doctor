<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class IsAdmin
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        // Prefer the RBAC permission check; fall back to the role-column helper
        // so access still works before/without the RBAC seeder.
        $allowed = $user && ($user->can('access-admin') || $user->canAccessAdmin());

        if (! $allowed) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login')
                ->withErrors(['email' => 'Access denied. Staff credentials required.']);
        }

        // If specific roles are required (e.g., middleware('admin:admin'))
        if (! empty($roles) && ! in_array($user->role, $roles)) {
            abort(403, 'You do not have permission to access this area.');
        }

        return $next($request);
    }
}
