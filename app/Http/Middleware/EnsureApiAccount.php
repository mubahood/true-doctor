<?php

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * The account behind an API request is still allowed to use it.
 *
 * Two things the web checks on every page and the API did not:
 *
 *  - A DISABLED account. Deactivation was only checked at sign-in, so a token
 *    issued before a member of staff left kept working for its whole 30 days —
 *    a phone in a former employee's pocket with the hospital's records on it.
 *    Now the next request refuses, and the token is deleted so it cannot be
 *    tried again.
 *  - A TEMPORARY PASSWORD. The web holds such an account on the "set your
 *    password" page (RequirePasswordChange); the API only reported the flag
 *    and served everything anyway. Now it serves only what the app needs to
 *    show that screen and act on it.
 *
 * Runs after auth:sanctum and before `subscribed`, so a disabled account is
 * told it is disabled rather than that its hospital's subscription ended.
 */
class EnsureApiAccount
{
    /** Reachable while the password is still a temporary one. */
    private const PASSWORD_ALLOWED = ['api.auth.me', 'api.auth.logout', 'api.auth.password', 'api.meta'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        // Explicitly false. The column is NOT NULL DEFAULT true, so a null here
        // is a model that never loaded the attribute — an active account, not
        // a disabled one. Every real request loads the user from the database.
        if ($user->is_active === false) {
            // The token this request presented, if it presented one — a
            // session-cookie request has none to revoke.
            PersonalAccessToken::findToken((string) $request->bearerToken())?->delete();

            return ApiResponse::error(
                ApiErrorCode::AccountDisabled,
                'This account has been disabled. Ask your hospital administrator.',
                401,
            );
        }

        if ($user->password_change_required && ! $request->routeIs(...self::PASSWORD_ALLOWED)) {
            return ApiResponse::error(
                ApiErrorCode::PasswordChangeRequired,
                'Set your own password before continuing.',
                403,
            );
        }

        return $next($request);
    }
}
