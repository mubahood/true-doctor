<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\Plan;
use App\Models\User;
use App\Support\Edition;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EditionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private ?string $installationMarker = null;

    protected function tearDown(): void
    {
        if ($this->installationMarker !== null && is_file($this->installationMarker)) {
            unlink($this->installationMarker);
        }

        parent::tearDown();
    }

    private function markInstalled(): void
    {
        $this->installationMarker = tempnam(sys_get_temp_dir(), 'td-installed-');
        file_put_contents($this->installationMarker, '{"edition":"standalone"}');
        config(['edition.installed_path' => $this->installationMarker]);
    }

    public function test_standalone_hides_platform_and_sales_surfaces(): void
    {
        config(['edition.name' => Edition::STANDALONE]);
        $this->markInstalled();

        $this->get('/')->assertRedirect(route('admin.login'));
        foreach (['/features', '/pricing', '/security', '/contact', '/register', '/super/hospitals', '/gateway/pesapal/callback'] as $path) {
            $this->assertSame(404, $this->get($path)->getStatusCode(), $path);
        }
        $this->post('/register')->assertNotFound();

        $this->seed(RbacSeeder::class);
        $hospital = Hospital::factory()->create();
        $user = User::factory()->create(['hospital_id' => $hospital->id, 'role' => 'hospital_admin']);
        $this->actingAs($user)->get('/admin/subscription')->assertNotFound();
        $this->actingAs($user)->get('/admin/settings')->assertNotFound();
    }

    public function test_standalone_refuses_a_second_hospital_even_after_the_first_is_deleted(): void
    {
        config(['edition.name' => Edition::STANDALONE]);

        $first = Hospital::factory()->create();
        $this->assertSame(1, $first->standalone_slot);
        $first->delete();

        $this->expectException(\DomainException::class);
        Hospital::factory()->create();
    }

    public function test_database_unique_slot_rejects_a_concurrent_second_hospital(): void
    {
        config(['edition.name' => Edition::STANDALONE]);
        Hospital::factory()->create();

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DB::table('hospitals')->insert([
            'name' => 'Second organisation',
            'slug' => 'second-organisation',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'standalone_slot' => 1,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_standalone_refuses_central_accounts_and_bypasses_subscriptions_for_hospital_staff(): void
    {
        config(['edition.name' => Edition::STANDALONE, 'tenancy.enforce_subscription' => true]);
        $this->markInstalled();
        $this->seed(RbacSeeder::class);
        $hospital = Hospital::factory()->create();

        try {
            User::factory()->create(['hospital_id' => null, 'role' => 'super_admin']);
            $this->fail('A central account was created on a standalone installation.');
        } catch (\DomainException) {
            // The account has no tenant and would otherwise see unscoped data.
        }

        $user = User::factory()->create(['hospital_id' => $hospital->id, 'role' => 'hospital_admin']);
        $this->actingAs($user)->get('/admin/onboarding')->assertOk();
    }

    public function test_saas_still_allows_more_than_one_hospital(): void
    {
        config(['edition.name' => Edition::SAAS]);

        Hospital::factory()->count(2)->create();

        $this->assertSame(2, Hospital::count());
    }

    public function test_the_default_seeder_does_not_create_a_platform_account_or_second_organisation(): void
    {
        config(['edition.name' => Edition::STANDALONE]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, Hospital::count());
        $this->assertSame(0, User::count());
        $this->assertSame(0, Plan::count());
        $this->assertDatabaseHas('roles', ['name' => 'hospital_admin']);
        $this->assertDatabaseHas('districts', ['name' => 'Kampala']);
    }

    public function test_uninstalled_standalone_redirects_web_and_refuses_api(): void
    {
        config([
            'edition.name' => Edition::STANDALONE,
            'edition.installed_path' => sys_get_temp_dir().'/td-no-such-installation-marker.json',
        ]);

        $this->get('/admin/login')->assertRedirect(url('/install.php'));
        $this->postJson('/api/v1/auth/login', [])->assertStatus(503);
    }

    public function test_an_installed_edition_cannot_be_switched_to_saas_with_an_env_change(): void
    {
        config(['edition.name' => Edition::SAAS]);
        $this->markInstalled();

        $this->get('/')->assertStatus(503);
    }
}
