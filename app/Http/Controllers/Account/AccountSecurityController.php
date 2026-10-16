<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Controller;
use App\Models\TrustedDevice;
use App\Services\AccountSecurityService;
use App\Services\TwoFactorService;
use App\Support\Demo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Account security, one page: two-step sign-in (turn on, recovery codes,
 * move to a new phone), trusted browsers, the apps signed in, and "sign out
 * everywhere". Also the page an owner or administrator is held on until
 * two-step sign-in is set up (RequireTwoFactor).
 */
class AccountSecurityController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly AccountSecurityService $security,
    ) {}

    public function show(Request $request): View
    {
        $user = $request->user();
        $pending = $this->twoFactor->pending($user);

        return view('account.security', [
            'user' => $user,
            'enabled' => $this->twoFactor->isEnabled($user),
            'required' => $this->twoFactor->isRequired($user),
            'mustSetUp' => $this->twoFactor->mustSetUp($user),
            'pending' => $pending,
            'qr' => $pending ? (string) QrCode::format('svg')->size(176)->margin(0)->errorCorrection('M')->generate($pending['uri']) : null,
            'codesLeft' => $this->twoFactor->recoveryCodesLeft($user),
            'recoveryCodes' => $request->session()->get('recovery_codes'),
            'trusted' => TrustedDevice::query()->where('user_id', $user->getKey())->where('expires_at', '>', now())->count(),
            'devices' => $this->security->devices($user),
            'demo' => Demo::isDemoUser($user),
        ]);
    }

    public function begin(Request $request): RedirectResponse
    {
        $this->refuseDemo($request);
        if (! $this->twoFactor->isEnabled($request->user())) {
            $this->twoFactor->begin($request->user());
        }

        return redirect()->route('account.security')->withFragment('two-factor');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $this->refuseDemo($request);
        $request->validate(['code' => ['required', 'string', 'max:20']], ['code.required' => 'Enter the 6-digit code the app shows.']);

        $codes = $this->twoFactor->confirm($request->user(), (string) $request->input('code'));
        if ($codes === null) {
            return back()->withErrors(['code' => 'That code is not right. Check the app shows True-Doctor and type the newest code.'])->withFragment('two-factor');
        }

        // This browser just proved it: it need not be asked again tonight.
        $request->session()->put('auth.at', now()->getTimestamp());

        return redirect()->route('account.security')
            ->with('recovery_codes', $codes)
            ->with('status', 'Two-step sign-in is on. Save the recovery codes below.')
            ->withFragment('two-factor');
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $this->refuseDemo($request);
        $request->validate(['password' => ['required', 'current_password']], $this->passwordMessages());

        $codes = $this->twoFactor->regenerateRecoveryCodes($request->user());

        return redirect()->route('account.security')
            ->with('recovery_codes', $codes)
            ->with('status', 'New recovery codes made. The old ones no longer work.')
            ->withFragment('two-factor');
    }

    /** Turn it off, or, for a role that must have it, start again on a new phone. */
    public function disable(Request $request): RedirectResponse
    {
        $this->refuseDemo($request);
        $request->validate(['password' => ['required', 'current_password']], $this->passwordMessages());

        $user = $request->user();
        $this->twoFactor->disable($user);
        Cookie::queue(Cookie::forget(TwoFactorChallengeController::trustCookie($user)));

        if ($this->twoFactor->isRequired($user)) {
            $this->twoFactor->begin($user);

            return redirect()->route('account.security')->with('status', 'Scan the new code with the new phone to finish.')->withFragment('two-factor');
        }

        return redirect()->route('account.security')->with('status', 'Two-step sign-in is off.');
    }

    public function forgetBrowsers(Request $request): RedirectResponse
    {
        $user = $request->user();
        $this->twoFactor->forgetDevices($user);
        Cookie::queue(Cookie::forget(TwoFactorChallengeController::trustCookie($user)));

        return redirect()->route('account.security')->with('status', 'Every browser will be asked for a code again.')->withFragment('browsers');
    }

    public function revokeDevice(Request $request, int $device): RedirectResponse
    {
        $this->security->revokeDevice($request->user(), $device);

        return redirect()->route('account.security')->with('status', 'That device is signed out.')->withFragment('devices');
    }

    public function signOutEverywhere(Request $request): RedirectResponse
    {
        $this->refuseDemo($request);
        $request->validate(['password' => ['required', 'current_password']], $this->passwordMessages());

        $user = $request->user();
        $this->security->signOutEverywhere($user);
        Cookie::queue(Cookie::forget(TwoFactorChallengeController::trustCookie($user)));

        // This browser stays signed in: its mark is renewed after the cut-off.
        $request->session()->regenerate();
        $request->session()->put('auth.at', now()->getTimestamp());

        return redirect()->route('account.security')->with('status', 'Every other browser and app is signed out.');
    }

    /** The demonstration's accounts are shared: one visitor must not lock the others out. */
    private function refuseDemo(Request $request): void
    {
        abort_if(Demo::isDemoUser($request->user()), 403, 'The demo accounts are shared, so their sign-in settings cannot be changed.');
    }

    /** @return array<string,string> */
    private function passwordMessages(): array
    {
        return [
            'password.required' => 'Enter your password to confirm.',
            'password.current_password' => 'That is not your password.',
        ];
    }
}
