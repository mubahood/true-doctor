<?php

namespace Tests\Feature\Api;

use App\Models\AuditEntry;
use App\Models\Hospital;
use App\Models\User;
use App\Models\UserAbility;
use App\Services\TwoFactorService;
use App\Support\Access\PermissionResolver;
use App\Support\CurrentHospital;
use App\Support\TwoFactor\Totp;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Two-step sign-in from the app (docs/api/security.md): a colleague's turned
 * off by whoever may edit their account, and your own turned off or given new
 * recovery codes behind your password. The same TwoFactorService as the web.
 */
class TwoFactorApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    private Hospital $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->h = Hospital::factory()->create();
        $this->other = Hospital::factory()->create();
    }

    private function user(string $role, ?Hospital $h = null): User
    {
        $u = User::factory()->create(['hospital_id' => ($h ?? $this->h)->id, 'role' => $role, 'password' => Hash::make('garden123')]);
        $u->syncSpatieRole();

        return $u;
    }

    private function as(User $u): User
    {
        app(PermissionResolver::class)->flush();
        Sanctum::actingAs($u);

        return $u;
    }

    /** Turn it on the way a person does; the recovery codes come back. @return list<string> */
    private function enable(User $user): array
    {
        $service = app(TwoFactorService::class);
        $secret = $service->begin($user)['secret'];

        return (array) $service->confirm($user, Totp::code($secret, Totp::step() - 1));
    }

    private function on(User $user): bool
    {
        return app(TwoFactorService::class)->isEnabled($user->fresh());
    }

    // ── A colleague's ───────────────────────────────────────────────────

    public function test_an_admin_turns_it_off_for_a_colleague_who_lost_a_phone(): void
    {
        $admin = $this->as($this->user('hospital_admin'));
        $nurse = $this->user('nurse');
        $this->enable($nurse);

        $show = $this->getJson("/api/v1/admin/users/{$nurse->id}")->assertOk();
        $this->assertTrue($show->json('data.two_factor_enabled'));
        $this->assertTrue($show->json('data.can_reset_two_factor'));

        $this->postJson("/api/v1/admin/users/{$nurse->id}/reset-two-factor")->assertOk()
            ->assertJsonPath('message', "Two-step sign-in is off for {$nurse->name}.")
            ->assertJsonPath('data.two_factor_enabled', false);
        $this->assertFalse($this->on($nurse));
        $this->assertTrue(AuditEntry::query()->where('description', 'Two-step sign-in turned off by a colleague')->where('causer_id', $admin->id)->exists());

        $this->postJson("/api/v1/admin/users/{$nurse->id}/reset-two-factor")->assertStatus(422)
            ->assertJsonPath('message', "Two-step sign-in is already off for {$nurse->name}.");
    }

    public function test_never_your_own_from_the_list_that_needs_your_password(): void
    {
        $admin = $this->as($this->user('hospital_admin'));
        $this->enable($admin);

        $this->assertFalse($this->getJson("/api/v1/admin/users/{$admin->id}")->json('data.can_reset_two_factor'));
        $this->postJson("/api/v1/admin/users/{$admin->id}/reset-two-factor")->assertStatus(422)
            ->assertJsonPath('errors.base.0', 'Turn your own off on Account security.');
        $this->assertTrue($this->on($admin));
    }

    public function test_only_who_may_edit_the_account_and_an_admins_only_by_an_admin(): void
    {
        $owner = $this->user('hospital_admin');
        $this->enable($owner);
        $nurse = $this->user('nurse');
        $this->enable($nurse);

        $this->as($this->user('doctor'));
        $this->postJson("/api/v1/admin/users/{$nurse->id}/reset-two-factor")->assertForbidden();

        // "Manage staff and settings" runs the other accounts, never an admin's.
        $manager = $this->user('receptionist');
        app(CurrentHospital::class)->set($this->h->id);
        UserAbility::create(['user_id' => $manager->id, 'ability' => 'manage_staff']);
        $this->as($manager);
        $this->assertFalse($this->getJson("/api/v1/admin/users/{$owner->id}")->assertOk()->json('data.can_reset_two_factor'));
        $this->postJson("/api/v1/admin/users/{$owner->id}/reset-two-factor")->assertForbidden();
        $this->assertTrue($this->on($owner));
        $this->postJson("/api/v1/admin/users/{$nurse->id}/reset-two-factor")->assertOk();
        $this->assertFalse($this->on($nurse));
    }

    public function test_another_hospitals_account_is_not_found(): void
    {
        $this->as($this->user('hospital_admin'));
        $foreign = $this->user('nurse', $this->other);
        $this->enable($foreign);

        $this->postJson("/api/v1/admin/users/{$foreign->id}/reset-two-factor")->assertNotFound();
        $this->assertTrue($this->on($foreign));
    }

    public function test_a_reset_sent_twice_with_one_key_is_done_once(): void
    {
        $this->as($this->user('hospital_admin'));
        $nurse = $this->user('nurse');
        $this->enable($nurse);

        $this->postJson("/api/v1/admin/users/{$nurse->id}/reset-two-factor", [], ['Idempotency-Key' => 'tf-1'])->assertOk();
        $this->postJson("/api/v1/admin/users/{$nurse->id}/reset-two-factor", [], ['Idempotency-Key' => 'tf-1'])->assertOk()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame(1, AuditEntry::query()->where('description', 'Two-step sign-in turned off by a colleague')->count());
    }

    // ── Your own ────────────────────────────────────────────────────────

    public function test_turning_your_own_off_needs_your_password(): void
    {
        $this->postJson('/api/v1/auth/two-factor/disable', ['password' => 'garden123'])->assertUnauthorized();

        $nurse = $this->as($this->user('nurse'));
        $this->enable($nurse);

        $this->postJson('/api/v1/auth/two-factor/disable', [])->assertStatus(422)->assertJsonPath('errors.password.0', 'Enter your password to confirm.');
        $this->postJson('/api/v1/auth/two-factor/disable', ['password' => 'wrong-one'])->assertStatus(422)->assertJsonPath('errors.password.0', 'That is not your password.');
        $this->assertTrue($this->on($nurse));

        $this->postJson('/api/v1/auth/two-factor/disable', ['password' => 'garden123'])->assertOk()
            ->assertJsonPath('message', 'Two-step sign-in is off.')
            ->assertJsonPath('data.two_factor_required', false);
        $this->assertFalse($this->on($nurse));
        $this->getJson('/api/v1/meta')->assertJsonPath('data.security.two_factor_enabled', false)->assertJsonPath('data.security.self_service', true);

        $this->postJson('/api/v1/auth/two-factor/disable', ['password' => 'garden123'])->assertStatus(409)->assertJsonPath('code', 'conflict');
    }

    public function test_for_a_role_that_must_have_it_turning_off_is_the_move_to_a_new_phone(): void
    {
        config(['auth.two_factor.enforce' => true]);
        $admin = $this->user('hospital_admin');
        $this->enable($admin);
        $this->as($admin);
        $this->getJson('/api/v1/dashboard')->assertOk();

        $this->postJson('/api/v1/auth/two-factor/disable', ['password' => 'garden123'])->assertOk()
            ->assertJsonPath('data.two_factor_required', true)
            ->assertJsonPath('message', 'Two-step sign-in is off. Set it up on the new phone to carry on.');
        $this->getJson('/api/v1/dashboard')->assertForbidden()->assertJsonPath('code', 'two_factor_setup_required');
        $this->postJson('/api/v1/auth/two-factor/setup')->assertOk();
    }

    public function test_new_recovery_codes_need_the_password_are_shown_once_and_never_kept(): void
    {
        $nurse = $this->as($this->user('nurse'));
        $old = $this->enable($nurse);

        $this->postJson('/api/v1/auth/two-factor/recovery-codes', ['password' => 'nope'])->assertStatus(422)->assertJsonPath('errors.password.0', 'That is not your password.');

        $res = $this->postJson('/api/v1/auth/two-factor/recovery-codes', ['password' => 'garden123'], ['Idempotency-Key' => 'rc-1'])->assertOk()
            ->assertJsonPath('message', 'New recovery codes made. The old ones no longer work.');
        $codes = $res->json('data.recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertSame(0, DB::table('idempotency_keys')->count(), 'a secret is never written to the idempotency store');

        $service = app(TwoFactorService::class);
        $this->assertFalse($service->verify($nurse->fresh(), $old[0]), 'the old codes no longer work');
        $this->assertTrue($service->verify($nurse->fresh(), $codes[0]));

        // The same key again is a new set (nothing was kept to replay), and the newest is the one that works.
        $again = $this->postJson('/api/v1/auth/two-factor/recovery-codes', ['password' => 'garden123'], ['Idempotency-Key' => 'rc-1'])->assertOk();
        $this->assertNull($again->headers->get('Idempotent-Replay'));
        $this->assertNotSame($codes, $again->json('data.recovery_codes'));
        $this->assertFalse($service->verify($nurse->fresh(), $codes[1]));
        $this->assertTrue($service->verify($nurse->fresh(), $again->json('data.recovery_codes.1')));
    }

    public function test_no_codes_to_replace_when_it_is_off(): void
    {
        $this->as($this->user('nurse'));
        $this->postJson('/api/v1/auth/two-factor/recovery-codes', ['password' => 'garden123'])->assertStatus(409);
    }

    public function test_setting_it_up_never_leaves_the_secret_in_the_idempotency_store(): void
    {
        $this->as($this->user('nurse'));

        $secret = $this->postJson('/api/v1/auth/two-factor/setup', [], ['Idempotency-Key' => 'su-1'])->assertOk()->json('data.secret');
        $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => Totp::code($secret, Totp::step())], ['Idempotency-Key' => 'su-2'])->assertOk()->assertJsonCount(8, 'data.recovery_codes');

        $this->assertSame(0, DB::table('idempotency_keys')->count());

        // The same confirm again (a lost answer) says it is on and where to get new codes.
        $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => Totp::code($secret, Totp::step())], ['Idempotency-Key' => 'su-2'])->assertStatus(409)
            ->assertJsonPath('message', 'Two-step sign-in is already on. If you did not see your recovery codes, make new ones in My account.');
    }

    public function test_a_kept_fingerprint_of_a_body_with_a_password_is_keyed(): void
    {
        $nurse = $this->as($this->user('nurse'));
        $this->enable($nurse);
        $this->postJson('/api/v1/auth/two-factor/disable', ['password' => 'garden123'], ['Idempotency-Key' => 'pw-1'])->assertOk();

        $stored = (string) DB::table('idempotency_keys')->where('key', 'pw-1')->value('request_hash');
        $this->assertNotSame('', $stored);
        $plain = hash('sha256', implode("\n", ['POST', 'api/v1/auth/two-factor/disable', '[]', '{"password":"garden123"}']));
        $this->assertNotSame($plain, $stored, 'a plain hash of the password body is never kept');
    }

    public function test_the_shared_demo_accounts_cannot_change_their_sign_in(): void
    {
        config(['demo.enabled' => true, 'demo.hospital' => $this->h->slug]);
        $this->as($this->user('nurse'));

        $this->postJson('/api/v1/auth/two-factor/disable', ['password' => 'garden123'])->assertForbidden();
        $this->postJson('/api/v1/auth/two-factor/recovery-codes', ['password' => 'garden123'])->assertForbidden();
    }
}
