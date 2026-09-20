<?php

namespace Tests\Feature\Offline;

use App\Models\Device;
use App\Models\Hospital;
use App\Models\User;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

/**
 * How a browser proves who it is to the sync API.
 *
 * Written after a bug that made the entire offline feature inert: the `api`
 * middleware group had no session middleware at all, so `auth:sanctum` could
 * only ever see a bearer token — and the Field Mode client sends none. A fully
 * signed-in browser got `200` on `/admin` and `401` on `/api/v1/sync/status`,
 * every single time. Nothing about it appeared in any test, because every
 * existing test authenticates with `Sanctum::actingAs()`, which sets the user
 * on the guard directly and therefore never needs a session to exist.
 *
 * So these tests deliberately do NOT use `Sanctum::actingAs()`. They assert the
 * middleware stack itself, which is the thing that was wrong.
 *
 * The decision behind it: Field Mode authenticates with the ordinary session
 * cookie rather than a personal access token. The cookie is HttpOnly and no
 * script on the origin can read it; a token would have to sit in IndexedDB on
 * a shared ward machine for its whole 30-day life. There is no credential on
 * the device at all, which is why there is nothing to wrap in a PIN.
 */
class SyncSessionAuthTest extends TestCase
{
    use RefreshDatabase;

    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($hospital->id);

        $this->nurse = User::factory()->create(['hospital_id' => $hospital->id, 'role' => 'nurse']);
        $this->nurse->syncSpatieRole();
    }

    // ── The stack ────────────────────────────────────────────────────────

    public function test_the_api_routes_can_see_a_session(): void
    {
        // The whole bug in one assertion. Without this middleware the `api`
        // group never starts a session, so a cookie cannot authenticate and
        // the sync endpoints are unreachable from the browser that needs them.
        $this->assertContains(
            EnsureFrontendRequestsAreStateful::class,
            app('router')->getMiddlewareGroups()['api'],
            'The api group has no stateful middleware: a signed-in browser cannot reach the sync API.',
        );
    }

    public function test_the_sync_routes_authenticate_and_resolve_a_hospital_in_that_order(): void
    {
        $route = collect(Route::getRoutes())->firstWhere(fn ($r) => $r->getName() === 'api.sync.push');

        $stack = array_map(
            fn ($m) => is_string($m) ? $m : $m::class,
            app('router')->gatherRouteMiddleware($route),
        );

        $auth = array_search('Illuminate\Auth\Middleware\Authenticate:sanctum', $stack, true);
        $hospital = array_search(\App\Http\Middleware\ResolveHospital::class, $stack, true);

        $this->assertNotFalse($auth, 'The push route is not authenticated.');
        $this->assertNotFalse($hospital, 'The push route does not resolve a hospital.');

        // Tenancy is resolved from the authenticated user, so it cannot run
        // first. Reversed, a push would be scoped to no hospital at all.
        $this->assertLessThan($hospital, $auth);
    }

    // ── A real browser round trip ────────────────────────────────────────

    public function test_a_session_cookie_reaches_the_sync_api(): void
    {
        // `actingAs`, not `Sanctum::actingAs`: this is the web session guard,
        // exactly as a browser that signed in at /admin/login would hold it.
        $this->actingAs($this->nurse)
            ->withHeader('Origin', config('app.url'))
            ->getJson('/api/v1/sync/status')
            ->assertOk()
            ->assertJsonPath('data.protocol_version', 1);
    }

    public function test_a_write_from_the_browser_is_csrf_protected(): void
    {
        // This is the reason the client reads `XSRF-TOKEN` out of the cookie
        // jar on every request rather than taking it from the page: the shell
        // is served from the service-worker cache, so a token baked into the
        // markup would be days old, and a stale token is refused exactly like
        // a missing one.
        //
        // It is asserted structurally because Laravel's CSRF middleware skips
        // itself outright under PHPUnit (`VerifyCsrfToken::runningUnitTests`),
        // so no feature test anywhere can observe a 419. The 419 itself was
        // confirmed against the running server with a real session cookie:
        // POST /api/v1/sync/register without the header answered 419, and with
        // it reached the controller.
        $pipeline = (new \ReflectionClass(EnsureFrontendRequestsAreStateful::class))
            ->getMethod('frontendMiddleware');
        $pipeline->setAccessible(true);

        $names = array_filter($pipeline->invoke(new EnsureFrontendRequestsAreStateful), 'is_string');

        $this->assertContains(\Illuminate\Session\Middleware\StartSession::class, $names);
        $this->assertContains(config('sanctum.middleware.validate_csrf_token'), $names);
    }

    public function test_a_write_from_the_browser_succeeds_with_its_csrf_token(): void
    {
        $this->actingAs($this->nurse)
            ->withHeader('Origin', config('app.url'))
            ->withHeader('X-XSRF-TOKEN', $this->xsrfToken())
            ->postJson('/api/v1/sync/register', [
                'device_uuid' => '11111111-1111-4111-8111-111111111111',
                'label' => 'Ward laptop',
            ])
            ->assertOk();

        $this->assertDatabaseHas('devices', ['device_uuid' => '11111111-1111-4111-8111-111111111111']);
    }

    public function test_a_signed_out_browser_is_told_so_rather_than_served(): void
    {
        $this->withHeader('Origin', config('app.url'))
            ->getJson('/api/v1/sync/status')
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }

    // ── Everything else still works ──────────────────────────────────────

    public function test_a_token_client_is_untouched_by_any_of_this(): void
    {
        // Integration clients send no Origin, so the stateful middleware never
        // engages and no CSRF token is asked of them. Breaking this would
        // break every non-browser consumer of the API at once.
        $token = $this->nurse->createToken('integration')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/register', [
                'device_uuid' => '22222222-2222-4222-8222-222222222222',
                'label' => 'Integration',
            ])
            ->assertOk();
    }

    public function test_a_super_admin_gets_the_hospital_they_switched_into(): void
    {
        // A behaviour that genuinely changed here, so it is pinned rather than
        // left to be discovered. `ResolveHospital` reads `viewing_hospital_id`
        // from the session for a super-admin, and guards that read on
        // `hasSession()` — which was never true on the API before. The panel
        // and the sync API now agree on which hospital a super-admin is in;
        // disagreeing would be far worse than either answer.
        $hospital = Hospital::factory()->create();
        $super = User::factory()->create(['hospital_id' => null, 'role' => 'super-admin']);
        $super->syncSpatieRole();

        $this->actingAs($super)
            ->withSession(['viewing_hospital_id' => $hospital->id])
            ->withHeader('Origin', config('app.url'))
            ->getJson('/api/v1/sync/status')
            ->assertOk()
            ->assertJsonPath('data.hospital_id', $hospital->id);
    }

    public function test_the_login_endpoint_is_still_reachable_without_a_session(): void
    {
        // It is the route a client uses when it has nothing yet, so it must
        // not have become unreachable by accident. 401 — not 419 and not 500 —
        // is the endpoint's own answer to credentials it does not recognise.
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'wrong'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }

    // ── Columns wide enough for what goes in them ────────────────────────

    public function test_a_device_can_store_a_whole_pull_cursor(): void
    {
        // Found on the running server, not here: `POST /sync/ack` answered 500
        // on EVERY call, for every device, with "Data too long for column
        // 'pull_cursor'". The cursor is encrypted — base64 of an IV, a
        // ciphertext and a MAC — and the real one measured 288 characters
        // against a varchar(255).
        //
        // SQLite does not enforce VARCHAR lengths at all, so the behavioural
        // test passes here and the same code fails in production. The column
        // TYPE is what is asserted, because the type is the thing that differs.
        //
        // The general rule this stands for: **a column length is not verified
        // by this test suite.** Check a new one against MySQL by hand.
        $this->assertSame('text', strtolower(Schema::getColumnType('devices', 'pull_cursor')));

        // And the round trip, which is what the column is for.
        $device = Device::create([
            'hospital_id' => $this->nurse->hospital_id,
            'user_id' => $this->nurse->id,
            'device_uuid' => '33333333-3333-4333-8333-333333333333',
            'label' => 'Ward laptop',
            'registered_at' => now(),
        ]);

        $cursor = encrypt(['revision' => 1841, 'streams' => array_fill(0, 12, 'patients')]);
        $this->assertGreaterThan(255, strlen($cursor), 'The fixture is not long enough to prove anything.');

        $device->update(['pull_cursor' => $cursor]);

        $this->assertSame($cursor, $device->fresh()->pull_cursor);
    }

    /** The token Laravel would have put in the readable XSRF-TOKEN cookie. */
    private function xsrfToken(): string
    {
        $this->startSession();

        return $this->app['session']->token();
    }
}
