<?php

namespace Tests\Feature\Api;

use App\Models\Hospital;
use App\Models\User;
use App\Support\DemoPersonas;
use App\Support\Edition;
use App\Support\Navigation;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/** The app's "Try the demo" door: GET/POST /api/v1/public/demo. */
class DemoApiTest extends TestCase
{
    use RefreshDatabase;

    private ?string $marker = null;

    protected function setUp(): void
    {
        parent::setUp();
        // DemoSeeder is dev-only by design; the demo door is on in local/demo.
        $this->app->detectEnvironment(fn () => 'local');
        $this->seed(RbacSeeder::class);
        $this->seed(PlanSeeder::class);
        $this->seed(DemoSeeder::class);
    }

    protected function tearDown(): void
    {
        if ($this->marker !== null && is_file($this->marker)) {
            unlink($this->marker);
        }
        parent::tearDown();
    }

    private function switchDemoOff(): void
    {
        $this->app->detectEnvironment(fn () => 'testing');
        config(['demo.enabled' => false]);
    }

    private function login(string $role): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/public/demo/login', ['role' => $role, 'device_name' => 'Test phone']);
    }

    public function test_disabled_demo_answers_enabled_false_and_login_404s(): void
    {
        $this->switchDemoOff();

        $this->getJson('/api/v1/public/demo')->assertOk()
            ->assertJsonPath('data.enabled', false)->assertJsonPath('data.roles', [])->assertJsonPath('data.hospital', null);
        $this->login('doctor')->assertNotFound();
    }

    public function test_no_demo_hospital_means_disabled(): void
    {
        Hospital::where('slug', 'general-hospital-a')->update(['slug' => 'renamed']);

        $this->getJson('/api/v1/public/demo')->assertOk()->assertJsonPath('data.enabled', false);
        $this->login('doctor')->assertNotFound();
    }

    public function test_it_lists_the_roles_that_exist_with_personas_and_missions(): void
    {
        $res = $this->getJson('/api/v1/public/demo')->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.hospital.name', 'Kato Medical Centre')
            ->assertJsonPath('data.hospital.currency', 'UGX');

        $roles = collect($res->json('data.roles'));
        $this->assertEqualsCanonicalizing(DemoPersonas::roles(), $roles->pluck('key')->all());
        $this->assertNotContains('super_admin', $roles->pluck('key')->all());

        foreach ($roles as $role) {
            $this->assertEqualsCanonicalizing(['key', 'label', 'icon', 'headline', 'can', 'minutes', 'missions'], array_keys($role));
            $this->assertCount(3, $role['can']);
            $this->assertIsInt($role['minutes']);
            $this->assertGreaterThanOrEqual(3, count($role['missions']));
            $this->assertLessThanOrEqual(4, count($role['missions']));
            foreach ($role['missions'] as $m) {
                $this->assertEqualsCanonicalizing(['id', 'title', 'hint', 'route'], array_keys($m));
            }
        }
    }

    public function test_a_role_nobody_holds_is_not_listed_and_cannot_sign_in(): void
    {
        User::where('role', 'radiologist')->delete();

        $keys = collect($this->getJson('/api/v1/public/demo')->json('data.roles'))->pluck('key');
        $this->assertNotContains('radiologist', $keys->all());
        $this->login('radiologist')->assertStatus(422);
    }

    public function test_the_listing_never_carries_an_email_or_password(): void
    {
        // The support block carries the vendor's own contact; the demo's accounts never appear.
        $data = $this->getJson('/api/v1/public/demo')->json('data');
        unset($data['support']);
        $body = json_encode($data);

        $this->assertStringNotContainsString('@', $body);
        $this->assertStringNotContainsStringIgnoringCase('password', $body);
        $this->assertStringNotContainsString(DemoSeeder::PASSWORD, $body);
    }

    public function test_other_hospitals_and_real_users_are_never_offered(): void
    {
        $real = Hospital::factory()->create(['name' => 'Real Hospital']);
        $mine = User::factory()->create(['hospital_id' => $real->id, 'role' => 'pharmacist', 'email' => 'real@real.test']);
        $mine->syncSpatieRole();
        // A look-alike address in a real hospital must not be taken for a demo account either.
        $fake = User::factory()->create(['hospital_id' => $real->id, 'role' => 'pharmacist', 'email' => 'x@test.com']);

        $res = $this->login('pharmacist')->assertOk();

        $this->assertNotSame($real->id, $res->json('data.user.hospital_id'));
        $this->assertNotContains($res->json('data.user.id'), [$mine->id, $fake->id]);
        $this->assertSame('pharmacy.a@test.com', $res->json('data.user.email'));
        $this->assertSame('Kato Medical Centre', $res->json('data.demo.hospital'));
    }

    public function test_an_unseeded_staff_account_in_the_demo_hospital_is_not_used(): void
    {
        $a = Hospital::where('slug', 'general-hospital-a')->firstOrFail();
        User::where('email', 'doctor.a@test.com')->delete();
        User::factory()->create(['hospital_id' => $a->id, 'role' => 'doctor', 'email' => 'visitor@example.com'])->syncSpatieRole();

        $this->login('doctor')->assertStatus(422);
        $this->assertNotContains('doctor', collect($this->getJson('/api/v1/public/demo')->json('data.roles'))->pluck('key')->all());
    }

    public function test_an_inactive_account_is_skipped_for_the_next_one_of_that_role(): void
    {
        $a = Hospital::where('slug', 'general-hospital-a')->firstOrFail();
        User::where('email', 'nurse.a@test.com')->update(['is_active' => false]);
        $this->login('nurse')->assertStatus(422);

        $second = User::factory()->create(['hospital_id' => $a->id, 'role' => 'nurse', 'email' => 'nurse2.a@test.com', 'is_active' => true]);
        $second->syncSpatieRole();
        $this->login('nurse')->assertOk()->assertJsonPath('data.user.id', $second->id);
    }

    public function test_every_role_can_sign_in_and_the_token_works_on_meta_as_a_demo_user(): void
    {
        foreach (DemoPersonas::roles() as $role) {
            $this->flushHeaders();
            $this->app['auth']->forgetGuards();
            $res = $this->login($role)->assertOk()
                ->assertJsonPath('data.password_change_required', false)
                ->assertJsonPath('data.demo.role', $role)
                ->assertJsonPath('data.user.role', $role)
                ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'role', 'hospital'], 'demo' => ['role', 'role_label', 'hospital']]]);

            $this->assertStringNotContainsString(DemoSeeder::PASSWORD, $res->getContent());

            $this->withToken($res->json('data.token'))->getJson('/api/v1/meta')->assertOk()
                ->assertJsonPath('data.demo.active', true)
                ->assertJsonPath('data.demo.role', $role)
                ->assertJsonPath('data.demo.hospital', 'Kato Medical Centre')
                ->assertJsonPath('data.demo.resets', false);
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_the_token_is_named_for_the_device_and_expires_in_a_day(): void
    {
        $token = PersonalAccessToken::findToken($this->login('doctor')->json('data.token'));

        $this->assertSame('demo-app:Test phone', $token->name);
        $this->assertNotNull($token->expires_at);
        $this->assertTrue($token->expires_at->between(now()->addHours(23), now()->addHours(25)));

        $this->travel(25)->hours();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->login('doctor')->json('data.token'))->getJson('/api/v1/meta')->assertOk();
    }

    public function test_an_expired_token_stops_working(): void
    {
        $plain = $this->login('doctor')->json('data.token');
        $this->travel(25)->hours();
        $this->app['auth']->forgetGuards();

        $this->withToken($plain)->getJson('/api/v1/meta')->assertUnauthorized();
    }

    public function test_the_login_does_not_change_the_account(): void
    {
        User::where('email', 'doctor.a@test.com')->update(['password_change_required' => true]);
        $hash = User::where('email', 'doctor.a@test.com')->value('password');

        $this->login('doctor')->assertOk()->assertJsonPath('data.password_change_required', false);

        $user = User::where('email', 'doctor.a@test.com')->first();
        $this->assertTrue((bool) $user->password_change_required);
        $this->assertSame($hash, $user->password);
    }

    public function test_unknown_roles_are_422_and_a_platform_account_is_unreachable(): void
    {
        $super = User::factory()->create(['hospital_id' => null, 'role' => 'super_admin', 'email' => 'admin@gmail.com', 'is_active' => true]);
        $super->syncSpatieRole();

        foreach (['super_admin', 'admin', 'nope', '', 'doctor.a@test.com', 'admin@gmail.com'] as $role) {
            $this->login($role)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        }
        $this->postJson('/api/v1/public/demo/login', [])->assertStatus(422);
        // An email or id sent by the client is never read.
        $res = $this->postJson('/api/v1/public/demo/login', ['role' => 'doctor', 'email' => 'admin@gmail.com', 'user_id' => 1])->assertOk();
        $this->assertSame('doctor', $res->json('data.user.role'));
        $this->assertFalse($res->json('data.user.is_super_admin'));
    }

    public function test_login_is_throttled_per_ip(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->login('nope')->assertStatus(422);
        }
        $this->login('doctor')->assertStatus(429);
    }

    public function test_standalone_edition_has_no_demo_door(): void
    {
        config(['edition.name' => Edition::STANDALONE]);
        $this->marker = tempnam(sys_get_temp_dir(), 'td-installed-');
        file_put_contents($this->marker, '{"edition":"standalone"}');
        config(['edition.installed_path' => $this->marker]);

        $this->getJson('/api/v1/public/demo')->assertNotFound();
        $this->login('doctor')->assertNotFound();
    }

    public function test_a_demo_user_cannot_change_the_password_but_a_normal_user_can(): void
    {
        $token = $this->login('doctor')->json('data.token');
        $this->withToken($token)->postJson('/api/v1/auth/password', [
            'current_password' => DemoSeeder::PASSWORD, 'password' => 'brandnew99', 'password_confirmation' => 'brandnew99',
        ])->assertForbidden()->assertJsonPath('code', 'forbidden')
            ->assertJsonPath('message', 'Demonstration accounts cannot change their password.');
        $this->assertTrue(Hash::check(DemoSeeder::PASSWORD, User::where('email', 'doctor.a@test.com')->value('password')));

        $this->app['auth']->forgetGuards();
        $real = Hospital::factory()->create();
        $user = User::factory()->create(['hospital_id' => $real->id, 'role' => 'hospital_admin', 'password' => Hash::make('secret123')]);
        $user->syncSpatieRole();
        $plain = $user->createToken('t')->plainTextToken;
        $this->withToken($plain)->postJson('/api/v1/auth/password', [
            'current_password' => 'secret123', 'password' => 'brandnew99', 'password_confirmation' => 'brandnew99',
        ])->assertOk();

        // And a real hospital's meta carries no demo key.
        $this->app['auth']->forgetGuards();
        $this->assertArrayNotHasKey('demo', $this->withToken($plain)->getJson('/api/v1/meta')->json('data'));
    }

    public function test_a_demo_admin_cannot_change_a_demo_accounts_password_email_or_role(): void
    {
        $token = $this->login('hospital_admin')->json('data.token');
        $doctor = User::where('email', 'doctor.a@test.com')->firstOrFail();

        $this->withToken($token)->postJson("/api/v1/admin/users/{$doctor->id}/reset-password", [
            'password' => 'brandnew99', 'password_confirmation' => 'brandnew99',
        ])->assertForbidden();
        $this->withToken($token)->putJson("/api/v1/admin/users/{$doctor->id}", [
            'name' => $doctor->name, 'email' => 'hacked@example.com', 'role' => $doctor->role, 'is_active' => true,
        ])->assertForbidden();
        $this->withToken($token)->putJson("/api/v1/admin/users/{$doctor->id}", [
            'name' => $doctor->name, 'email' => $doctor->email, 'role' => 'nurse', 'is_active' => true,
        ])->assertForbidden();
        $this->withToken($token)->putJson("/api/v1/admin/users/{$doctor->id}", [
            'name' => $doctor->name, 'email' => $doctor->email, 'role' => $doctor->role, 'password' => 'brandnew99', 'password_confirmation' => 'brandnew99', 'is_active' => true,
        ])->assertForbidden();

        $fresh = $doctor->fresh();
        $this->assertSame('doctor.a@test.com', $fresh->email);
        $this->assertSame('doctor', $fresh->role);
        $this->assertTrue(Hash::check(DemoSeeder::PASSWORD, $fresh->password));
    }

    public function test_every_mission_is_a_real_route_the_role_can_open_from_its_menu(): void
    {
        foreach (DemoPersonas::all() as $role => $persona) {
            $user = User::where('email', 'like', '%.a@test.com')->where('role', $role)->firstOrFail();
            $menu = $this->menuRoutes($user);

            $this->assertContains($persona['icon'], $this->appIcons(), "{$role} icon");
            foreach ($persona['missions'] as $m) {
                $this->assertTrue(Route::has($m['route']), "{$role}: {$m['route']} is not a route");
                $this->assertContains($m['route'], $menu, "{$role}: {$m['route']} is not in the role's menu");
            }
            $this->assertSame(count($persona['missions']), count(array_unique(array_column($persona['missions'], 'id'))));
        }
    }

    public function test_nurse_and_receptionist_have_an_offline_challenge(): void
    {
        foreach (['nurse', 'receptionist'] as $role) {
            $titles = array_column(DemoPersonas::for($role)['missions'], 'title');
            $this->assertContains('Offline challenge', $titles);
        }
    }

    /** @return list<string> */
    private function menuRoutes(User $user): array
    {
        $routes = [];
        foreach (Navigation::for($user, false)['sections'] as $section) {
            foreach ($section['entries'] as $entry) {
                foreach ($entry['type'] === 'link' ? [$entry] : $entry['items'] as $item) {
                    $routes[] = $item['route'];
                }
            }
        }

        return $routes;
    }

    /** The icons the Flutter app ships; mirrored here so a typo cannot reach a phone. */
    private function appIcons(): array
    {
        return ['fa-heart-pulse', 'fa-user-nurse', 'fa-id-badge', 'fa-clipboard-list', 'fa-droplet', 'fa-lungs', 'fa-sack-dollar', 'fa-file-pen', 'fa-people-group'];
    }
}
