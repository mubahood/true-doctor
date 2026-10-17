<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\TwoFactor\Totp;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Two-step sign-in on the web and over the API, and "sign out everywhere". */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function admin(array $extra = []): User
    {
        $hospital = Hospital::factory()->create();
        $user = User::factory()->create(['hospital_id' => $hospital->id, 'role' => 'hospital_admin', 'password' => Hash::make('garden123')] + $extra);
        $user->syncSpatieRole();

        return $user;
    }

    /** Turn it on the way a person does, and hand back the secret. */
    private function enable(User $user): string
    {
        $service = app(TwoFactorService::class);
        $secret = $service->begin($user)['secret'];
        // A code from a step already past, so the next real code is still unused.
        $this->assertNotNull($service->confirm($user, Totp::code($secret, Totp::step() - 1)));

        return $secret;
    }

    private function codeNow(string $secret): string
    {
        return Totp::code($secret, Totp::step());
    }

    public function test_turning_it_on_needs_a_code_from_the_app_and_gives_recovery_codes_once(): void
    {
        $user = $this->admin();
        $service = app(TwoFactorService::class);
        $secret = $service->begin($user)['secret'];

        $this->assertFalse($service->isEnabled($user), 'offered is not in force');
        $this->assertNull($service->confirm($user, '000000'));
        $codes = $service->confirm($user, Totp::code($secret, Totp::step()));

        $this->assertCount(8, $codes);
        $this->assertTrue($service->isEnabled($user->fresh()));
        $this->assertNotContains($codes[0], (array) $user->fresh()->two_factor_recovery_codes, 'only hashes are kept');
        $this->assertStringNotContainsString($secret, (string) \DB::table('users')->where('id', $user->id)->value('two_factor_secret'), 'the secret is encrypted at rest');
    }

    public function test_a_code_cannot_be_used_twice_and_a_recovery_code_works_once(): void
    {
        $user = $this->admin();
        $service = app(TwoFactorService::class);
        $secret = $service->begin($user)['secret'];
        $codes = $service->confirm($user, Totp::code($secret, Totp::step() - 1));

        $now = $this->codeNow($secret);
        $this->assertTrue($service->verify($user, $now));
        $this->assertFalse($service->verify($user, $now), 'replayed');

        $this->assertTrue($service->verify($user, strtolower($codes[3])));
        $this->assertFalse($service->verify($user, $codes[3]), 'spent');
        $this->assertSame(7, $service->recoveryCodesLeft($user->fresh()));
    }

    public function test_the_web_asks_for_the_code_before_anybody_is_signed_in(): void
    {
        $user = $this->admin();
        $secret = $this->enable($user);

        $this->post('/admin/login', ['email' => $user->email, 'password' => 'garden123', 'remember' => '1'])
            ->assertRedirect(route('two-factor.challenge'))
            ->assertCookieMissing(\Illuminate\Support\Facades\Auth::guard('web')->getRecallerName());
        $this->assertGuest();

        $this->post(route('two-factor.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post(route('two-factor.verify'), ['code' => $this->codeNow($secret), 'trust' => '1'])
            ->assertRedirect(route('admin.dashboard'))
            ->assertCookie('td_trust_'.$user->id);
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_trusted_browser_skips_the_code(): void
    {
        $user = $this->admin();
        $this->enable($user);
        $token = app(TwoFactorService::class)->trust($user, 'Chrome', '127.0.0.1');

        $this->withCookie('td_trust_'.$user->id, $token)
            ->post('/admin/login', ['email' => $user->email, 'password' => 'garden123'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_challenge_page_without_a_password_first_goes_back_to_sign_in(): void
    {
        $this->get(route('two-factor.challenge'))->assertRedirect(route('admin.login'));
        $this->post(route('two-factor.verify'), ['code' => '123456'])->assertRedirect(route('admin.login'));
    }

    public function test_an_owner_is_held_on_account_security_until_it_is_set_up_when_enforced(): void
    {
        config(['auth.two_factor.enforce' => true]);
        $user = $this->admin();

        $this->actingAs($user)->get(route('admin.dashboard'))->assertRedirect(route('account.security').'#two-factor');
        $this->actingAs($user)->get(route('account.security'))->assertOk()->assertSee('Set up two-step sign-in');

        $this->actingAs($user)->post(route('account.security.begin'))->assertRedirect();
        $page = $this->actingAs($user)->get(route('account.security'))->assertOk()->assertSee('Type this key instead')->getContent();
        $this->assertStringContainsString('<svg', $page, 'the QR code is drawn');

        $secret = (string) $user->fresh()->two_factor_secret;
        $this->actingAs($user)->post(route('account.security.confirm'), ['code' => $this->codeNow($secret)])
            ->assertSessionHas('recovery_codes');
        $this->actingAs($user->fresh())->get(route('admin.dashboard'))->assertOk();
    }

    public function test_a_nurse_is_never_forced_and_a_demo_account_never_is(): void
    {
        config(['auth.two_factor.enforce' => true]);
        $nurse = User::factory()->create(['hospital_id' => Hospital::factory()->create()->id, 'role' => 'nurse']);

        $this->assertFalse(app(TwoFactorService::class)->isRequired($nurse));
    }

    public function test_the_api_answers_with_a_challenge_then_a_token(): void
    {
        $user = $this->admin();
        $secret = $this->enable($user);

        $first = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'garden123', 'device_name' => 'Pixel'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'two_factor_required')
            ->assertJsonMissingPath('data.token');
        $challenge = $first->json('data.challenge');

        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => '000000'])->assertStatus(422);
        $this->postJson('/api/v1/auth/two-factor', ['challenge' => 'forged', 'code' => $this->codeNow($secret)])->assertStatus(401);

        $ok = $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => $this->codeNow($secret)])
            ->assertOk()
            ->assertJsonPath('data.recovery_codes_left', 8);
        $this->assertNotEmpty($ok->json('data.token'));
        $this->assertSame('Pixel', $user->tokens()->first()->name);
    }

    public function test_an_expired_challenge_is_refused(): void
    {
        $user = $this->admin();
        $secret = $this->enable($user);
        $challenge = app(TwoFactorService::class)->challengeFor($user, 'Pixel');

        $this->travel(11)->minutes();

        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => $this->codeNow($secret)])->assertStatus(401);
    }

    public function test_the_api_holds_an_owner_until_set_up_and_lets_them_set_it_up(): void
    {
        config(['auth.two_factor.enforce' => true]);
        $user = $this->admin();
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->getJson('/api/v1/dashboard')->assertStatus(403)->assertJsonPath('code', 'two_factor_setup_required');
        $this->getJson('/api/v1/meta')->assertOk()->assertJsonPath('data.security.two_factor_required', true)->assertJsonPath('data.security.two_factor_enabled', false);

        $secret = $this->postJson('/api/v1/auth/two-factor/setup')->assertOk()->json('data.secret');
        $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => '000000'])->assertStatus(422);
        $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => $this->codeNow($secret)])->assertOk()->assertJsonCount(8, 'data.recovery_codes');

        $this->getJson('/api/v1/dashboard')->assertOk();
    }

    public function test_sign_out_everywhere_ends_other_sessions_and_apps_but_keeps_this_one(): void
    {
        $user = $this->admin();
        $keep = $user->createToken('This phone');
        $user->createToken('Old tablet');

        $this->withToken($keep->plainTextToken)->postJson('/api/v1/auth/sign-out-everywhere', ['password' => 'nope'])->assertStatus(422);
        $this->withToken($keep->plainTextToken)->postJson('/api/v1/auth/sign-out-everywhere', ['password' => 'garden123'])->assertOk();

        $this->assertSame(['This phone'], $user->tokens()->pluck('name')->all());
        $this->assertNotNull($user->fresh()->signed_out_everywhere_at);
    }

    public function test_a_web_session_from_before_sign_out_everywhere_is_ended(): void
    {
        $user = $this->admin();
        $this->post('/admin/login', ['email' => $user->email, 'password' => 'garden123'])->assertRedirect();
        $this->assertAuthenticatedAs($user);

        $this->travel(5)->seconds();
        app(\App\Services\AccountSecurityService::class)->signOutEverywhere($user);
        // A real next request loads the person afresh; the test's guard still holds the old copy.
        $this->app['auth']->forgetGuards();

        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
        $this->assertGuest();
    }

    public function test_the_page_signing_out_everywhere_stays_signed_in(): void
    {
        $user = $this->admin();
        $this->post('/admin/login', ['email' => $user->email, 'password' => 'garden123']);
        $user->createToken('Old tablet');

        $this->travel(5)->seconds();
        $this->post(route('account.security.everywhere'), ['password' => 'garden123'])->assertRedirect(route('account.security'));
        $this->app['auth']->forgetGuards();

        $this->get(route('admin.dashboard'))->assertOk();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_devices_are_listed_and_one_can_be_signed_out_but_not_somebody_elses(): void
    {
        $user = $this->admin();
        $mine = $user->createToken('This phone');
        $tablet = $user->createToken('Old tablet');
        $other = $this->admin()->createToken('Not yours');

        $this->withToken($mine->plainTextToken)->getJson('/api/v1/auth/devices')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonFragment(['name' => 'This phone', 'current' => true]);

        $this->withToken($mine->plainTextToken)->deleteJson('/api/v1/auth/devices/'.$other->accessToken->id)->assertNotFound();
        $this->withToken($mine->plainTextToken)->deleteJson('/api/v1/auth/devices/'.$tablet->accessToken->id)->assertOk();
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_it_is_optional_by_default_and_an_admin_can_turn_it_off_for_a_colleague_who_lost_a_phone(): void
    {
        $admin = $this->admin();
        $this->assertFalse(app(TwoFactorService::class)->mustSetUp($admin), 'nobody is forced by default');

        app(\App\Support\CurrentHospital::class)->set($admin->hospital_id);
        $colleague = User::factory()->create(['hospital_id' => $admin->hospital_id, 'role' => 'hospital_admin']);
        $colleague->syncSpatieRole();
        $this->enable($colleague);
        $this->enable($admin);

        \Livewire\Livewire::actingAs($admin)->test(\App\Livewire\Users\Index::class)->call('resetTwoFactor', $colleague->id)->assertHasNoErrors();
        $this->assertFalse(app(TwoFactorService::class)->isEnabled($colleague->fresh()));

        // Not your own (that needs your password), and not an admin's by someone who is not one.
        \Livewire\Livewire::actingAs($admin)->test(\App\Livewire\Users\Index::class)->call('resetTwoFactor', $admin->id);
        $this->assertTrue(app(TwoFactorService::class)->isEnabled($admin->fresh()));
        $desk = User::factory()->create(['hospital_id' => $admin->hospital_id, 'role' => 'receptionist']);
        $desk->syncSpatieRole();
        $this->assertFalse($desk->can('update', $admin), 'the desk cannot open, let alone change, an admin account');
    }
}
