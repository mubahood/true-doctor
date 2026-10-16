<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a web session that began before the person chose "sign out
 * everywhere" (users.signed_out_everywhere_at). Every sign-in stamps the
 * session with `auth.at` (AppServiceProvider); whatever the session store is
 * (file, database, Redis), an older stamp is simply let go.
 */
class EnsureSessionIsCurrent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->signed_out_everywhere_at !== null && $request->hasSession()) {
            $at = (int) $request->session()->get('auth.at', 0);

            if ($at < $user->signed_out_everywhere_at->getTimestamp()) {
                /** @var \Illuminate\Auth\SessionGuard $guard */
                $guard = Auth::guard('web');
                $guard->logoutCurrentDevice();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $request->expectsJson()
                    ? response()->json(['message' => 'Signed out.'], 401)
                    : redirect()->route('admin.login')->with('status', 'You were signed out from another device. Sign in again.');
            }
        }

        return $next($request);
    }
}
