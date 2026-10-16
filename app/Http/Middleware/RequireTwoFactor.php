<?php

namespace App\Http\Middleware;

use App\Services\TwoFactorService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds an owner, an administrator or the super admin on Account security
 * until two-step sign-in is set up (when the installation enforces it), the
 * way RequirePasswordChange holds a temporary password. Runs after it, so a
 * temporary password is replaced first.
 */
class RequireTwoFactor
{
    private const EXEMPT_ROUTES = [
        'account.security', 'account.security.*',
        'admin.logout', 'logout', 'demo.leave',
        'password.change', 'password.update',
        'two-factor.*', 'verification.*',
    ];

    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->password_change_required && ! $request->routeIs(...self::EXEMPT_ROUTES) && $this->twoFactor->mustSetUp($user)) {
            if ($request->expectsJson()) {
                abort(403, 'Set up two-step sign-in first.');
            }

            return redirect()->route('account.security')->withFragment('two-factor');
        }

        return $next($request);
    }
}
