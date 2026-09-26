<?php

namespace Tests\Feature\Api;

use App\Enums\SubscriptionStatus;
use App\Models\Hospital;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * The account behind an app's token: still allowed, still on a temporary
 * password, still paid for. Real tokens throughout — the point is what
 * happens to a token issued BEFORE the account changed.
 */
class AccountStateApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->seed(\Database\Seeders\PlanSeeder::class);
    }

    private function staff(array $extra = []): User
    {
        $user = User::factory()->create($extra + [
            'hospital_id' => Hospital::factory()->create()->id,
            'role' => 'nurse',
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
        $user->syncSpatieRole();

        return $user;
    }

    private function tokenFor(User $user, string $password = 'secret123'): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $password, 'device_name' => 'test phone'])
            ->assertOk()->json('data.token');
    }

    /** A fresh client each call: Laravel's test client otherwise remembers the user. */
    private function api(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    // ── A disabled account ───────────────────────────────────────────────

    public function test_a_token_stops_working_the_moment_its_account_is_disabled(): void
    {
        $user = $this->staff();
        $token = $this->tokenFor($user);

        $this->api($token)->getJson('/api/v1/patients')->assertOk();

        $user->update(['is_active' => false]);

        $this->api($token)->getJson('/api/v1/patients')
            ->assertStatus(401)
            ->assertJsonPath('code', 'account_disabled');

        $this->assertSame(0, PersonalAccessToken::count(), 'the token of a disabled account was left in place');

        // …and re-enabling the account does not bring the old token back.
        $user->update(['is_active' => true]);
        $this->api($token)->getJson('/api/v1/patients')->assertStatus(401);
    }

    // ── A temporary password ─────────────────────────────────────────────

    public function test_a_temporary_password_holds_the_app_on_the_password_screen(): void
    {
        $user = $this->staff(['password_change_required' => true]);
        $token = $this->tokenFor($user);

        $this->api($token)->getJson('/api/v1/patients')
            ->assertStatus(403)
            ->assertJsonPath('code', 'password_change_required');

        // What the app needs to show that screen still answers.
        $this->api($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->api($token)->getJson('/api/v1/meta')->assertOk()->assertJsonPath('data.user.password_change_required', true);
    }

    public function test_setting_a_password_unlocks_the_app(): void
    {
        $user = $this->staff(['password_change_required' => true]);
        $token = $this->tokenFor($user);

        $this->api($token)->postJson('/api/v1/auth/password', [
            'current_password' => 'secret123',
            'password' => 'kampala',
            'password_confirmation' => 'kampala',
        ])->assertOk()->assertJsonPath('data.password_change_required', false);

        $this->assertFalse($user->fresh()->password_change_required);
        $this->assertTrue(Hash::check('kampala', $user->fresh()->password));
        $this->api($token)->getJson('/api/v1/patients')->assertOk();
    }

    /** @return array<string, array{0: array<string,string>, 1: string, 2: string}> */
    public static function refusals(): array
    {
        return [
            'wrong current password' => [['current_password' => 'nope', 'password' => 'garden', 'password_confirmation' => 'garden'], 'current_password', 'That is not the password you signed in with.'],
            'too short' => [['current_password' => 'secret123', 'password' => 'abc', 'password_confirmation' => 'abc'], 'password', 'The new password needs at least 6 characters.'],
            'not confirmed' => [['current_password' => 'secret123', 'password' => 'garden', 'password_confirmation' => 'gardens'], 'password', 'The two new passwords do not match.'],
            'same as before' => [['current_password' => 'secret123', 'password' => 'secret123', 'password_confirmation' => 'secret123'], 'password', 'Choose a password different from the one you signed in with.'],
        ];
    }

    /** The same words as the web page, field by field, in the envelope. */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function test_a_refused_password_says_why_like_the_web_does(array $input, string $field, string $message): void
    {
        $token = $this->tokenFor($this->staff(['password_change_required' => true]));

        $this->api($token)->postJson('/api/v1/auth/password', $input)
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath("errors.{$field}.0", $message);
    }

    public function test_changing_the_password_signs_out_every_other_device(): void
    {
        $user = $this->staff();
        $phone = $this->tokenFor($user);
        $laptop = $this->tokenFor($user);

        $this->api($phone)->postJson('/api/v1/auth/password', [
            'current_password' => 'secret123', 'password' => 'kampala', 'password_confirmation' => 'kampala',
        ])->assertOk();

        $this->api($phone)->getJson('/api/v1/auth/me')->assertOk();
        $this->api($laptop)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    // ── A lapsed subscription ────────────────────────────────────────────

    public function test_a_lapsed_subscription_has_its_own_code_and_leaves_the_way_out_open(): void
    {
        config()->set('tenancy.enforce_subscription', true);

        $user = $this->staff();
        Subscription::withoutGlobalScopes()->create([
            'hospital_id' => $user->hospital_id,
            'plan_id' => Plan::where('slug', 'starter')->value('id'),
            'starts_at' => Carbon::now()->subMonths(3),
            'ends_at' => Carbon::now()->subMonth(),
            'status' => SubscriptionStatus::Expired,
        ]);
        $token = $this->tokenFor($user);

        $this->api($token)->getJson('/api/v1/patients')
            ->assertStatus(403)
            ->assertJsonPath('code', 'subscription_ended');

        // The app can still say who you are, show why, and sign out.
        $this->api($token)->getJson('/api/v1/meta')->assertOk()->assertJsonPath('data.subscription.grants_access', false);
        $this->api($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->api($token)->postJson('/api/v1/auth/logout')->assertOk();
    }
}
