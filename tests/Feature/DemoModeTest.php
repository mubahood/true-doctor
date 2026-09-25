<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Support\CurrentHospital;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The public demonstration.
 *
 * A demo anybody can sign in to with a password printed on the page is a
 * deliberate thing to build, and the password is not what makes it safe.
 * These are the four things that do, and each is asserted here:
 *
 *   1. It is a switch, not an accident of APP_ENV.
 *   2. The platform super admin NEVER gets the published password, whatever
 *      the switch says. That account can see every hospital.
 *   3. A demo account cannot read another hospital's records.
 *   4. The reset can only ever touch the demonstration tenant.
 */
class DemoModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(PlanSeeder::class);
    }

    /**
     * Seed the demo the way a real deployment would: production + the switch.
     *
     * Through artisan with --force rather than $this->seed(), because in a
     * production environment Laravel's own guard stops and asks whether you
     * really meant it — which is exactly the behaviour we want in the wild
     * and a hang in a test.
     */
    private function seedDemoInProduction(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config()->set('demo.enabled', true);
        $this->runSeeder(DemoSeeder::class);
    }

    private function runSeeder(string $class): void
    {
        $this->artisan('db:seed', ['--class' => $class, '--force' => true])->run();
    }

    // ── 1. A switch, not an accident ─────────────────────────────────────

    public function test_the_demo_is_off_until_it_is_switched_on(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config()->set('demo.enabled', false);
        $this->runSeeder(DemoSeeder::class);

        // The seeder refused, so there is nothing to show and no door.
        $this->assertSame(0, User::where('email', 'like', '%@test.com')->count());
        $this->get('/test-login')->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('Try the demo');
    }

    public function test_switching_it_on_opens_the_door_in_production(): void
    {
        $this->seedDemoInProduction();

        $this->get('/test-login')->assertOk()->assertSee('doctor.a@test.com');
        $this->get('/')->assertOk()->assertSee('Try the demo');
        $this->get(route('pricing'))->assertOk()->assertSee('Try the demo');
    }

    public function test_a_developers_machine_gets_it_without_asking(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config()->set('demo.enabled', false);
        $this->seed(DemoSeeder::class);

        $this->get('/test-login')->assertOk();
    }

    // ── 2. The line that must never move ─────────────────────────────────

    /**
     * The super admin can see EVERY hospital on the platform. A published
     * password on it would hand every tenant's patient records to anybody
     * who read the documentation.
     */
    public function test_the_super_admin_never_gets_the_published_password(): void
    {
        $this->runSeeder(\Database\Seeders\AdminUserSeeder::class);

        $before = User::where('email', 'admin@gmail.com')->value('password');
        $this->assertNotNull($before, 'the super admin was not seeded');

        $this->seedDemoInProduction();

        $after = User::where('email', 'admin@gmail.com')->first();

        $this->assertSame($before, $after->password, 'the demo seeder changed the super admin password in production');
        $this->assertFalse(
            Hash::check(DemoSeeder::PASSWORD, (string) $after->password),
            'the super admin can be signed into with the demo password',
        );
    }

    /** …and the same when somebody sets APP_ENV=demo on a live box. */
    public function test_not_even_with_app_env_demo(): void
    {
        $this->runSeeder(\Database\Seeders\AdminUserSeeder::class);
        $before = User::where('email', 'admin@gmail.com')->value('password');

        $this->app->detectEnvironment(fn () => 'demo');
        config()->set('demo.enabled', true);
        $this->runSeeder(DemoSeeder::class);

        $this->assertSame($before, User::where('email', 'admin@gmail.com')->value('password'));
    }

    public function test_the_super_admin_is_not_on_the_rail_either(): void
    {
        $this->runSeeder(\Database\Seeders\AdminUserSeeder::class);
        $this->seedDemoInProduction();

        $html = $this->get('/test-login')->assertOk()->getContent();

        $this->assertStringNotContainsString('admin@gmail.com', $html);
        foreach (User::whereNull('hospital_id')->pluck('email') as $email) {
            $this->assertStringNotContainsString((string) $email, $html);
        }
    }

    // ── 3. A visitor cannot reach anybody else's records ─────────────────

    public function test_a_demo_visitor_cannot_see_another_hospitals_patients(): void
    {
        $this->seedDemoInProduction();

        // A real hospital, alongside the demo, with a real patient in it.
        $real = Hospital::factory()->create(['name' => 'Paying Hospital', 'slug' => 'paying']);
        app(CurrentHospital::class)->set($real->id);
        $theirs = Patient::factory()->create(['hospital_id' => $real->id, 'first_name' => 'Confidential']);

        // Somebody is signed in to the demonstration. Signed in directly
        // rather than through the form: what is being tested here is the
        // tenancy boundary, and StaffSignInTest already holds the door.
        $demoUser = User::where('email', 'doctor.a@test.com')->firstOrFail();
        $this->actingAs($demoUser);
        app(CurrentHospital::class)->set($demoUser->hospital_id);

        // The demo password really is the published one — that is the point
        // of a demonstration, and it is safe because of the boundary below.
        $this->assertTrue(Hash::check(DemoSeeder::PASSWORD, (string) $demoUser->password));

        // The other hospital's patient is not listed and not reachable.
        $this->get(route('admin.patients.index'))->assertOk()->assertDontSee('Confidential');
        $this->get(route('admin.patients.show', $theirs))->assertNotFound();
    }

    // ── 4. The reset cannot reach a real hospital ────────────────────────

    public function test_the_reset_refuses_when_the_demo_is_off(): void
    {
        config()->set('demo.enabled', false);

        $this->artisan('demo:reset --force')
            ->expectsOutputToContain('DEMO_MODE is off')
            ->assertFailed();
    }

    /**
     * The check that stops a mistyped slug or a copied .env from emptying a
     * customer: one member of staff who is not a seeded demo account and the
     * whole thing refuses.
     */
    public function test_the_reset_refuses_a_hospital_with_real_staff(): void
    {
        config()->set('demo.enabled', true);

        $real = Hospital::factory()->create(['slug' => 'not-a-demo']);
        $staff = User::factory()->create([
            'hospital_id' => $real->id,
            'email' => 'matron@realhospital.test',
            'role' => 'nurse',
        ]);
        $staff->syncSpatieRole();

        Patient::factory()->count(2)->create(['hospital_id' => $real->id]);

        // Pointed straight at it by config, and forced.
        config()->set('demo.hospital', 'not-a-demo');

        $this->artisan('demo:reset --force')
            ->expectsOutputToContain('REFUSED')
            ->assertFailed();

        // And nothing was touched.
        $this->assertSame(2, Patient::withoutGlobalScopes()->where('hospital_id', $real->id)->count());
    }

    public function test_the_reset_refuses_a_hospital_with_no_staff_at_all(): void
    {
        config()->set('demo.enabled', true);
        Hospital::factory()->create(['slug' => 'empty-shell']);
        config()->set('demo.hospital', 'empty-shell');

        $this->artisan('demo:reset --force')->assertFailed();
    }

    public function test_the_reset_refuses_a_slug_that_does_not_exist(): void
    {
        config()->set('demo.enabled', true);
        config()->set('demo.hospital', 'no-such-hospital');

        $this->artisan('demo:reset --force')->assertFailed();
    }

    /** It empties the demo tenant and leaves every other hospital alone. */
    public function test_the_reset_touches_only_the_demonstration_tenant(): void
    {
        $this->seedDemoInProduction();
        config()->set('demo.hospital', 'general-hospital-a');

        $demo = Hospital::where('slug', 'general-hospital-a')->firstOrFail();

        $real = Hospital::factory()->create(['slug' => 'paying']);
        app(CurrentHospital::class)->set($real->id);
        Patient::factory()->count(3)->create(['hospital_id' => $real->id]);

        $theirsBefore = Patient::withoutGlobalScopes()->where('hospital_id', $real->id)->count();
        $this->assertSame(3, $theirsBefore);

        $this->artisan('demo:reset --force')->assertSuccessful();

        // The other hospital is untouched.
        $this->assertSame(
            $theirsBefore,
            Patient::withoutGlobalScopes()->where('hospital_id', $real->id)->count(),
            'the reset deleted another hospital\'s patients',
        );

        // The demo still works afterwards — emptied AND rebuilt.
        $this->assertGreaterThan(0, User::where('hospital_id', $demo->id)->count());
        $this->get('/test-login')->assertOk()->assertSee('doctor.a@test.com');
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $this->seedDemoInProduction();
        config()->set('demo.hospital', 'general-hospital-a');

        $demo = Hospital::where('slug', 'general-hospital-a')->firstOrFail();
        $before = Patient::withoutGlobalScopes()->where('hospital_id', $demo->id)->count();

        $this->artisan('demo:reset --dry-run')->assertSuccessful();

        $this->assertSame($before, Patient::withoutGlobalScopes()->where('hospital_id', $demo->id)->count());
    }

    // ── The invitation to start a real hospital ──────────────────────────

    public function test_a_demo_visitor_is_invited_to_create_their_own(): void
    {
        $this->seedDemoInProduction();

        $demoUser = User::where('email', 'doctor.a@test.com')->firstOrFail();
        $this->actingAs($demoUser);
        app(CurrentHospital::class)->set($demoUser->hospital_id);

        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Create your own hospital')
            ->assertSee('This is the demonstration hospital.');
    }

    /**
     * The contract that matters. A hospital paying for the service must never
     * be invited to sign up for the thing it is already paying for.
     */
    public function test_a_real_hospital_is_never_invited_to_sign_up(): void
    {
        $this->seedDemoInProduction();

        $real = Hospital::factory()->create(['slug' => 'paying', 'name' => 'Paying Hospital']);
        $staff = User::factory()->create([
            'hospital_id' => $real->id,
            'role' => 'hospital_admin',
            'email' => 'admin@payinghospital.test',
        ]);
        $staff->syncSpatieRole();

        $this->actingAs($staff);
        app(CurrentHospital::class)->set($real->id);

        $html = $this->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Create your own hospital', $html);
        $this->assertStringNotContainsString('demonstration hospital', $html);
        $this->assertStringNotContainsString('tb-demo-cta', $html);
    }

    public function test_nobody_is_invited_when_the_demo_is_switched_off(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config()->set('demo.enabled', false);

        $hospital = Hospital::factory()->create(['slug' => 'general-hospital-a']);
        $staff = User::factory()->create(['hospital_id' => $hospital->id, 'role' => 'hospital_admin']);
        $staff->syncSpatieRole();

        $this->actingAs($staff);
        app(CurrentHospital::class)->set($hospital->id);

        // Same slug as the demo, but the demo is off — so there is no
        // demonstration and nothing to invite anybody away from.
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('Create your own hospital');
    }

    public function test_leaving_the_demo_signs_you_out_onto_the_sign_up_form(): void
    {
        $this->seedDemoInProduction();

        $demoUser = User::where('email', 'doctor.a@test.com')->firstOrFail();
        $this->actingAs($demoUser);

        // CSRF is disabled for these two only. These tests run as
        // `production`, where Laravel does not waive it in tests — which is
        // right, and the token is not what is being asserted here. The form
        // in the dialog carries one.
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        $this->post(route('demo.leave'))
            ->assertRedirect(route('register'))
            ->assertSessionHas('status');

        $this->assertGuest();
    }

    /** No redirect parameter to smuggle a destination into. */
    public function test_leaving_cannot_be_pointed_anywhere_else(): void
    {
        $this->seedDemoInProduction();
        $this->actingAs(User::where('email', 'doctor.a@test.com')->firstOrFail());

        // CSRF is disabled for these two only. These tests run as
        // `production`, where Laravel does not waive it in tests — which is
        // right, and the token is not what is being asserted here. The form
        // in the dialog carries one.
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        $this->post(route('demo.leave'), [
            'redirect' => 'https://evil.test/phish',
            'url' => 'https://evil.test/phish',
            'intended' => 'https://evil.test/phish',
        ])->assertRedirect(route('register'));

        $this->assertGuest();
    }

    public function test_leaving_needs_somebody_to_be_signed_in(): void
    {
        $this->post(route('demo.leave'))->assertRedirect(route('admin.login'));
    }

    /** The helper is the thing everything else asks; hold it directly. */
    public function test_the_demo_test_is_the_tenant_not_the_email(): void
    {
        $this->seedDemoInProduction();

        $demoHospital = Hospital::where('slug', 'general-hospital-a')->firstOrFail();

        // A staff account a visitor made while poking around: no @test.com
        // address, every bit as temporary as the rest of the demonstration.
        $theirs = User::factory()->create([
            'hospital_id' => $demoHospital->id,
            'email' => 'somebody@example.test',
        ]);

        $this->assertTrue(\App\Support\Demo::isDemoUser($theirs));

        // And the platform super admin, who belongs to no hospital.
        $this->runSeeder(\Database\Seeders\AdminUserSeeder::class);
        $super = User::where('email', 'admin@gmail.com')->first();
        $this->assertFalse(\App\Support\Demo::isDemoUser($super));
        $this->assertFalse(\App\Support\Demo::isDemoUser(null));
    }

    // ── The schedule ─────────────────────────────────────────────────────

    /** Turning the demo on must not by itself start something that deletes. */
    public function test_the_nightly_reset_needs_its_own_switch(): void
    {
        config()->set('demo.enabled', true);
        config()->set('demo.reset.enabled', false);

        $this->assertFalse(
            $this->scheduleContains('demo:reset'),
            'switching the demo on scheduled a job that deletes rows',
        );
    }

    private function scheduleContains(string $needle): bool
    {
        // Re-evaluate routes/console.php under the current config.
        $schedule = new \Illuminate\Console\Scheduling\Schedule;
        $this->app->instance(\Illuminate\Console\Scheduling\Schedule::class, $schedule);
        require base_path('routes/console.php');

        foreach ($schedule->events() as $event) {
            if (str_contains($event->command ?? '', $needle)) {
                return true;
            }
        }

        return false;
    }
}
