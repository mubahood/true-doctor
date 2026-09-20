<?php

namespace Tests\Feature\Auth;

use App\Models\Hospital;
use App\Models\User;
use App\Support\StaffSession;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * The two doors and the one lock.
 *
 * `/admin/login` is the hospital's own. `/test-login` is the demonstration's,
 * and it exists only where the accounts it lists exist. Both post to the same
 * action, which is the point: a second authentication path would be a second
 * place for the rate limiter and the account checks to drift.
 *
 * What is held here is everything somebody could get wrong about that: the
 * length of the session, whether the box that promises it is honoured, who is
 * turned away, and — the one that is easiest to leave undone — that the
 * hospital's own sign-in page never lists a single account.
 */
class StaffSignInTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->hospital = Hospital::factory()->create();
    }

    private function staff(string $role = 'hospital_admin', array $attributes = []): User
    {
        $user = User::factory()->create([
            'hospital_id' => $this->hospital->id,
            'role' => $role,
            'password' => bcrypt('password1'),
        ] + $attributes);
        $user->syncSpatieRole();

        return $user;
    }

    private function rememberCookie(\Illuminate\Testing\TestResponse $response): ?Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if (str_starts_with($cookie->getName(), 'remember_web')) {
                return $cookie;
            }
        }

        return null;
    }

    // ── It lets the right people in ──────────────────────────────────────

    public function test_staff_sign_in_and_land_on_the_dashboard(): void
    {
        $user = $this->staff();

        $this->post('/admin/login', ['email' => $user->email, 'password' => 'password1'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_is_refused_and_says_so_on_the_email_field(): void
    {
        $user = $this->staff();

        $this->from('/admin/login')
            ->post('/admin/login', ['email' => $user->email, 'password' => 'not-the-password'])
            ->assertRedirect('/admin/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * A deactivated account must not keep a session.
     *
     * The credentials were right, so Auth::attempt succeeded and a session
     * exists by the time the check runs. Leaving it standing would be a
     * signed-in deactivated user one redirect away from the panel.
     */
    public function test_a_deactivated_account_is_turned_away_and_left_signed_out(): void
    {
        $user = $this->staff('nurse', ['is_active' => false]);

        $this->post('/admin/login', ['email' => $user->email, 'password' => 'password1'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsString(
            'deactivated',
            (string) session('errors')?->first('email'),
        );
    }

    // ── For ninety days ──────────────────────────────────────────────────

    public function test_the_remember_cookie_lasts_ninety_days(): void
    {
        $user = $this->staff();

        $response = $this->post('/admin/login', [
            'email' => $user->email,
            'password' => 'password1',
            'remember_present' => '1',
            'remember' => '1',
        ]);

        $cookie = $this->rememberCookie($response);

        $this->assertNotNull($cookie, 'no remember cookie was issued');

        $days = (int) round(($cookie->getExpiresTime() - time()) / 86400);

        // A day either side: the cookie is minted a moment after `time()`.
        $this->assertEqualsWithDelta(StaffSession::REMEMBER_DAYS, $days, 1);
        $this->assertSame(90, StaffSession::REMEMBER_DAYS, 'the hospital asked for ninety days');
    }

    /**
     * The box is drawn ticked, so a post that carries no opinion gets it.
     *
     * An unticked checkbox posts nothing at all, which is why the hidden
     * companion field exists — without it "unticked" and "no such field" are
     * the same request, and the ambiguous one has to resolve to the default
     * the screen shows.
     */
    public function test_remember_is_on_by_default_when_the_form_says_nothing(): void
    {
        $user = $this->staff();

        $response = $this->post('/admin/login', ['email' => $user->email, 'password' => 'password1']);

        $this->assertNotNull($this->rememberCookie($response));
    }

    public function test_unticking_the_box_really_does_turn_it_off(): void
    {
        $user = $this->staff();

        // What a browser posts for a ticked-by-default box the user unticked:
        // the companion field, and no `remember`.
        $response = $this->post('/admin/login', [
            'email' => $user->email,
            'password' => 'password1',
            'remember_present' => '1',
        ]);

        $this->assertNull(
            $this->rememberCookie($response),
            'the box was unticked and a ninety-day cookie was issued anyway',
        );
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_checkbox_is_drawn_ticked(): void
    {
        // The promise on the screen and the default on the server are two
        // different pieces of code, and this is the one that notices when
        // somebody changes only one of them.
        $html = $this->get('/admin/login')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/name="remember"[^>]*checked|checked[^>]*name="remember"/',
            $html,
        );
        $this->assertStringContainsString('name="remember_present"', $html);
        $this->assertStringContainsString('90 days', $html);
    }

    // ── Already signed in ────────────────────────────────────────────────

    public function test_somebody_already_signed_in_is_sent_to_their_dashboard(): void
    {
        $this->actingAs($this->staff());

        foreach (['/admin/login', '/register'] as $door) {
            $this->get($door)->assertRedirect(route('admin.dashboard'));
        }
    }

    public function test_the_public_pages_offer_a_dashboard_instead_of_a_sign_up(): void
    {
        $user = $this->staff();

        $this->get('/')->assertOk()->assertSee('Sign up');

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertSee('Go to your dashboard')
            ->assertDontSee('Sign up');
    }

    // ── The two doors ────────────────────────────────────────────────────

    /**
     * The gap this closed: a real hospital's sign-in page was also a list of
     * logins, so anybody who saw it once learned such a list exists.
     */
    public function test_the_hospitals_own_sign_in_page_lists_no_accounts(): void
    {
        $this->seed(PlanSeeder::class);
        $this->app->detectEnvironment(fn () => 'local');
        $this->seed(DemoSeeder::class);

        $html = $this->get('/admin/login')->assertOk()->getContent();

        $this->assertStringNotContainsString('@test.com', $html);
        $this->assertStringNotContainsString(DemoSeeder::PASSWORD, $html);
        $this->assertStringNotContainsString('data-demo-email', $html);
    }

    public function test_the_demonstration_door_lists_them_and_points_at_the_same_action(): void
    {
        $this->seed(PlanSeeder::class);
        $this->app->detectEnvironment(fn () => 'local');
        $this->seed(DemoSeeder::class);

        $html = $this->get('/test-login')->assertOk()->getContent();

        $this->assertStringContainsString('doctor.a@test.com', $html);
        $this->assertStringContainsString('data-demo-email', $html);
        // One lock: the demonstration form posts to the same place.
        $this->assertStringContainsString('action="'.route('admin.login').'"', $html);
    }

    /**
     * The platform super admin is not a hospital role, so it demonstrates
     * nothing — and a one-click sign-in to the account that can see EVERY
     * tenant has no business on a page built to be handed to strangers.
     */
    public function test_the_demonstration_rail_never_offers_the_super_admin(): void
    {
        $this->seed(PlanSeeder::class);
        $this->app->detectEnvironment(fn () => 'local');
        $this->seed(DemoSeeder::class);

        $html = $this->get('/test-login')->assertOk()->getContent();

        $this->assertStringNotContainsString('admin@gmail.com', $html);
        $this->assertStringNotContainsString('Super Admin', $html);

        // …and nobody without a hospital, whoever they turn out to be.
        foreach (User::whereNull('hospital_id')->pluck('email') as $email) {
            $this->assertStringNotContainsString((string) $email, $html, "{$email} has no hospital and is on the demo rail");
        }

        // The hospital roles are all still there — this removed one account,
        // not the rail.
        foreach (['doctor.a@test.com', 'nurse.a@test.com', 'admin.b@test.com'] as $email) {
            $this->assertStringContainsString($email, $html);
        }
    }

    public function test_the_demonstration_door_does_not_exist_without_demo_accounts(): void
    {
        // Local, but nothing seeded — there is nothing to demonstrate, so
        // there is no door and no hint that there could be one.
        $this->app->detectEnvironment(fn () => 'local');

        $this->get('/test-login')->assertNotFound();
    }

    public function test_the_demonstration_door_is_not_reachable_in_production(): void
    {
        $this->seed(PlanSeeder::class);
        $this->app->detectEnvironment(fn () => 'local');
        $this->seed(DemoSeeder::class);

        // The accounts exist in the database; the environment is what closes
        // the door. Both halves are needed, and this is the half that matters.
        $this->app->detectEnvironment(fn () => 'production');

        $this->get('/test-login')->assertNotFound();
    }

    public function test_no_public_page_advertises_the_demonstration_in_production(): void
    {
        $this->seed(PlanSeeder::class);
        $this->app->detectEnvironment(fn () => 'local');
        $this->seed(DemoSeeder::class);
        $this->app->detectEnvironment(fn () => 'production');

        foreach (['/', '/pricing', '/admin/login'] as $page) {
            $this->assertStringNotContainsString(
                'Try the demo',
                (string) $this->get($page)->getContent(),
                "{$page} advertises the demonstration in production",
            );
        }
    }

    // ── Still rate limited ───────────────────────────────────────────────

    public function test_five_wrong_passwords_lock_the_door(): void
    {
        $user = $this->staff();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/admin/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        $this->post('/admin/login', ['email' => $user->email, 'password' => 'password1'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsString(
            'seconds',
            (string) session('errors')?->first('email'),
        );
    }

    // ── Signing out ──────────────────────────────────────────────────────

    public function test_signing_out_clears_the_long_lived_cookie_too(): void
    {
        $user = $this->staff();

        $this->post('/admin/login', [
            'email' => $user->email, 'password' => 'password1',
            'remember_present' => '1', 'remember' => '1',
        ]);
        $this->assertAuthenticatedAs($user);

        $response = $this->post('/admin/logout')->assertRedirect(route('home'));

        $this->assertGuest();

        // Laravel clears the cookie by sending an already-expired one. A
        // ninety-day session that survived its own sign-out would be the
        // worst possible version of this feature.
        $cookie = $this->rememberCookie($response);
        if ($cookie !== null) {
            $this->assertLessThanOrEqual(time(), $cookie->getExpiresTime());
        }

        // And the token itself is cycled, so a cookie kept from before is dead.
        $this->assertNotSame('', (string) $user->fresh()->remember_token);
        $this->assertFalse(Auth::check());
    }
}
