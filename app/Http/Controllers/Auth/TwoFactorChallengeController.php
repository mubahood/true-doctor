<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\StaffSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The second step of signing in on the web: the password was right, now the
 * code from the authenticator app (or a recovery code). Nobody is signed in
 * until it passes, and no "keep me signed in" cookie exists before then.
 */
class TwoFactorChallengeController extends Controller
{
    /** The cookie that says "this browser passed and asked to be remembered". */
    public static function trustCookie(User $user): string
    {
        return 'td_trust_'.$user->getKey();
    }

    public function show(Request $request): View|RedirectResponse
    {
        if ($this->pendingUser($request) === null) {
            return redirect()->route('admin.login');
        }

        return view('auth.two-factor-challenge');
    }

    public function store(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $user = $this->pendingUser($request);
        if ($user === null) {
            return redirect()->route('admin.login')->with('status', 'That took too long. Sign in again.');
        }

        $request->validate(['code' => ['required', 'string', 'max:20']], ['code.required' => 'Enter the code from your authenticator app.']);

        $key = 'two-factor:'.$user->getKey();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Too many wrong codes. Wait '.RateLimiter::availableIn($key).' seconds and try again.']);
        }

        if (! $twoFactor->verify($user, (string) $request->input('code'))) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['code' => 'That code is not right. Use the newest code in the app, or one of your recovery codes.']);
        }
        RateLimiter::clear($key);

        $remember = (bool) $request->session()->pull('login.remember', false);
        $request->session()->forget(['login.id', 'login.at']);

        StaffSession::apply();
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        if ($request->boolean('trust')) {
            $token = $twoFactor->trust($user, (string) $request->userAgent(), $request->ip());
            Cookie::queue(self::trustCookie($user), $token, (int) config('auth.two_factor.trust_days', 30) * 24 * 60, null, null, null, true, false, 'lax');
        }

        if ($twoFactor->recoveryCodesLeft($user) <= 2) {
            $request->session()->flash('status', 'You have '.$twoFactor->recoveryCodesLeft($user).' recovery codes left. Make new ones under Account security.');
        }

        return redirect()->intended(route('admin.dashboard'));
    }

    /** The person whose password was right, while the challenge is still fresh. */
    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get('login.id');
        $at = (int) $request->session()->get('login.at', 0);

        if ($id === null || $at < now()->subMinutes((int) config('auth.two_factor.challenge_minutes', 10))->getTimestamp()) {
            return null;
        }

        return User::query()->find($id);
    }
}
