<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Support\Demo;
use App\Support\DemoDoor;
use App\Support\StaffSession;
use App\Support\SupportContact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * The public pages that are content in config/landing.php: one per practice
 * (/for/{practice}), and the walkthrough, the app, about and the demo door.
 */
class PublicPageController extends Controller
{
    public function practice(string $practice): View
    {
        $pages = (array) config('landing.practices');
        abort_unless(isset($pages[$practice]), 404);

        return view('marketing.for', [
            'slug' => $practice,
            'page' => $pages[$practice],
            'others' => array_diff_key($pages, [$practice => true]),
        ]);
    }

    public function walkthrough(): View
    {
        return view('marketing.walkthrough', ['page' => config('landing.pages.walkthrough'), 'support' => SupportContact::current()]);
    }

    public function app(): View
    {
        $url = (string) config('services.mobile.download_url');

        return view('marketing.get-app', [
            'page' => config('landing.pages.get-app'),
            'appUrl' => $url !== '' ? $url : null,
            'qr' => $url !== '' ? (string) QrCode::format('svg')->size(150)->margin(0)->generate($url) : null,
        ]);
    }

    public function about(): View
    {
        return view('marketing.about', ['page' => config('landing.pages.about')]);
    }

    /** GET /demo - pick a role. */
    public function demo(): View
    {
        abort_unless(AuthenticatedSessionController::demoAvailable(), 404);

        return view('marketing.demo', [
            'page' => config('landing.pages.demo'),
            'roles' => DemoDoor::roles(),
            'resets' => Demo::resets(),
        ]);
    }

    /**
     * POST /demo/{role} - walk in. A form post (never a link) so a crawler or a
     * prefetch can never sign anybody in; the account is the server's choice.
     */
    public function enterDemo(Request $request, string $role): RedirectResponse
    {
        abort_unless(AuthenticatedSessionController::demoAvailable(), 404);

        $user = DemoDoor::accounts()->get($role);
        if ($user === null) {
            return redirect()->route('demo')->with('status', 'That role is not open in the demo right now. Pick another.');
        }

        // A real person signed in does not lose their session to a demo click;
        // a visitor already in the demo just changes role.
        if (Auth::check()) {
            if (! Demo::isDemoUser(Auth::user())) {
                return redirect()->route('admin.dashboard')->with('status', 'You are signed in to your own hospital. Sign out first to open the demo.');
            }
            /** @var \Illuminate\Auth\SessionGuard $guard */
            $guard = Auth::guard('web');
            $guard->logoutCurrentDevice();
        }
        StaffSession::apply();
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        app(\App\Services\TrafficRecorder::class)->action($request, 'demo_open');

        return redirect()->route('admin.dashboard');
    }
}
