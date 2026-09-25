<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Support\DemoAccount;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Staff sign-in.
 *
 * There are two doors and one lock. `/admin/login` is the door a hospital
 * uses: a form, nothing else on the page. `/test-login` is the door a
 * demonstration uses: the same form with the seeded accounts listed beside it,
 * and it exists only where those accounts exist.
 *
 * Both post to the same action. A second authentication path would be a second
 * place for the rate limiter, the deactivated-account check and the staff-only
 * check to drift out of step, and the one that got less attention would be the
 * one somebody found.
 */
class AuthenticatedSessionController extends Controller
{
    /** The hospital's own sign-in screen. Never lists an account. */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * The demonstration sign-in screen.
     *
     * Lives behind the same check that seeds the accounts it lists, so on a
     * real deployment the route answers 404 rather than rendering an empty
     * rail that hints such a thing exists.
     */
    public function createTest(): View
    {
        abort_unless(self::demoAvailable(), 404);

        return view('auth.test-login', [
            'demoAccounts' => self::demoAccounts(),
            // One source of truth for the password the rail fills in: the
            // seeder that set it. A view has no business knowing about
            // seeders, so it arrives here instead of being reached for.
            'demoPassword' => DemoSeeder::PASSWORD,
        ]);
    }

    /**
     * Whether this installation has a demonstration door at all.
     *
     * Two conditions, and both have to hold. The switch has to be on — a
     * deliberate `DEMO_MODE`, not a side effect of APP_ENV, because a live
     * site wanting a public demo is a normal thing to want and should not
     * require pretending to be a development machine. And the accounts have
     * to actually exist, so the rail can never advertise a door that opens
     * onto nothing.
     *
     * A developer's machine gets it without asking: local is a demonstration
     * of the product by definition.
     *
     * Deliberately not memoised. A cached answer is a thing that can be
     * stale, which for a door is the wrong kind of thing to be.
     */
    public static function demoAvailable(): bool
    {
        if (! config('demo.enabled') && ! app()->environment(['local', 'demo'])) {
            return false;
        }

        // `exists()`, not the list. This is asked on the hospital's own
        // sign-in page, which has no business pulling a set of email
        // addresses into memory — or into a debug bar — to decide whether to
        // show one link.
        return User::query()->where('email', 'like', '%@test.com')->exists();
    }

    /**
     * Seeded demo HOSPITAL staff, read from the database so the rail can
     * never advertise an account that does not exist.
     *
     * The platform super admin is deliberately not among them. It is not a
     * hospital role, so it demonstrates nothing about the product; what it
     * does do is put a one-click sign-in to the account that can see every
     * tenant on a page whose whole purpose is to be handed to strangers.
     * Somebody who needs it knows where the sign-in form is.
     *
     * @return Collection<int, DemoAccount>
     */
    private static function demoAccounts(): Collection
    {
        if (! config('demo.enabled') && ! app()->environment(['local', 'demo'])) {
            return collect();
        }

        return User::query()
            ->where('email', 'like', '%@test.com')
            ->whereNotNull('hospital_id')
            ->where('is_admin', false)
            ->with('hospital:id,name')
            ->orderBy('hospital_id')
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'role', 'hospital_id', 'is_admin'])
            ->map(fn (User $user) => DemoAccount::fromUser($user));
    }

    /**
     * Handle an incoming authentication request.
     *
     * The two rejections below re-throw as a validation error on `email` so
     * they land on the form rather than on a blank page — and both tear the
     * session down first, because a half-signed-in deactivated account is
     * worse than no session at all.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        /** @var User $user */
        $user = Auth::user();

        if ($user->is_active === false) {
            $this->abandon($request, 'This account has been deactivated. Please contact your administrator.');
        }

        // Every account is staff in True-Doctor; the public never signs in.
        if (! $user->canAccessAdmin()) {
            $this->abandon($request, 'Access denied. Staff credentials are required.');
        }

        // Session fixation: a new id for the newly-privileged session. It runs
        // after the two checks so a rejected attempt never gets a fresh one.
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    /** Destroy an authenticated session. */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('status', 'You have been signed out.');
    }

    /**
     * Undo a sign-in that got as far as the credentials but no further.
     *
     * @throws ValidationException
     */
    private function abandon(Request $request, string $message): never
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        throw ValidationException::withMessages(['email' => $message]);
    }
}
