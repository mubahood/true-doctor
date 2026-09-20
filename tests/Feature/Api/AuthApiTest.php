<?php

namespace Tests\Feature\Api;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function staff(Hospital $h, string $role = 'receptionist'): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role, 'password' => Hash::make('secret123'), 'is_active' => true]);
        $u->syncSpatieRole();

        return $u;
    }

    public function test_login_issues_a_token_in_the_envelope(): void
    {
        $h = Hospital::factory()->create();
        $u = $this->staff($h);

        $res = $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'secret123']);

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('code', 'ok')
            ->assertJsonStructure(['success', 'code', 'message', 'data' => ['token', 'user' => ['id', 'role', 'permissions']], 'errors']);
        $this->assertNotEmpty($res->json('data.token'));
    }

    public function test_bad_credentials_are_rejected_in_the_envelope(): void
    {
        $h = Hospital::factory()->create();
        $u = $this->staff($h);

        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'wrong'])
            ->assertStatus(401)->assertJsonPath('success', false)->assertJsonPath('code', 'unauthenticated');
    }

    public function test_disabled_account_cannot_log_in(): void
    {
        $h = Hospital::factory()->create();
        $u = $this->staff($h);
        $u->update(['is_active' => false]);

        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'secret123'])
            ->assertStatus(403)->assertJsonPath('code', 'forbidden');
    }

    public function test_me_requires_a_token_and_returns_the_user(): void
    {
        $h = Hospital::factory()->create();
        $u = $this->staff($h);

        $this->getJson('/api/v1/auth/me')->assertStatus(401)->assertJsonPath('success', false);

        $token = $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'secret123'])->json('data.token');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', $u->email);
    }

    public function test_logout_revokes_the_token(): void
    {
        $h = Hospital::factory()->create();
        $u = $this->staff($h);
        $token = $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'secret123'])->json('data.token');
        $this->assertSame(1, $u->tokens()->count());

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        // The token row is revoked (deleted) — the bearer no longer resolves.
        $this->assertSame(0, $u->fresh()->tokens()->count());
    }
}
