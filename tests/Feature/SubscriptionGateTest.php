<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Models\Hospital;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Tests\TestCase;

/**
 * The `subscribed` gate (EnsureSubscribed) is applied to every tenant surface:
 * the admin web group, the admin JSON drawer, the Sanctum API group, and — via
 * Livewire's persistent middleware — every Livewire update request. It was
 * previously aliased but attached to no route at all.
 *
 * Deliberately narrow, like RequireOnboarding: the subscription page (and the
 * checkout it posts to) must stay reachable — a hospital that cannot pay must
 * still be able to reach the page that lets it pay. A plain GET for an admin
 * who can fix it redirects there with a clear reason instead of a raw 403;
 * everyone else (who cannot subscribe) gets a plain, on-brand explanation.
 */
class SubscriptionGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        config(['tenancy.enforce_subscription' => true]);
    }

    private function admin(Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $u->syncSpatieRole();

        return $u;
    }

    private function subscribe(Hospital $h, SubscriptionStatus $status, ?string $endsAt = null): Subscription
    {
        return Subscription::factory()->for($h)->for(Plan::factory())->create([
            'status' => $status,
            'starts_at' => now()->subDay(),
            'ends_at' => $endsAt,
        ]);
    }

    public function test_admin_routes_redirect_the_configuring_admin_to_the_subscription_page_when_lapsed(): void
    {
        $h = Hospital::factory()->create();
        $this->subscribe($h, SubscriptionStatus::Expired, now()->subMonth()->toDateTimeString());

        $this->actingAs($this->admin($h))
            ->get(route('admin.departments.index'))
            ->assertRedirect(route('admin.subscription.index'));
    }

    /** The subscription page — and the checkout it posts to — must stay reachable, or a lapsed hospital can never pay to fix it. */
    public function test_the_subscription_page_and_checkout_stay_reachable_when_lapsed(): void
    {
        $h = Hospital::factory()->create();
        $this->subscribe($h, SubscriptionStatus::Expired, now()->subMonth()->toDateTimeString());
        $plan = Plan::factory()->create(['is_active' => true]);

        $admin = $this->admin($h);
        $this->actingAs($admin)->get(route('admin.subscription.index'))->assertOk();

        // The checkout POST itself would redirect externally on success; here it just
        // must not be blocked by the subscription gate before it gets a chance to run.
        $response = $this->actingAs($admin)->post(route('admin.subscription.checkout', $plan));
        $this->assertNotSame(403, $response->getStatusCode());
    }

    /** Non-admin staff cannot fix a lapsed subscription, so they get a plain explanation, not a redirect loop. */
    public function test_non_admin_staff_see_a_blocked_page_when_lapsed(): void
    {
        $h = Hospital::factory()->create();
        $this->subscribe($h, SubscriptionStatus::Expired, now()->subMonth()->toDateTimeString());
        $doctor = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor']);
        $doctor->syncSpatieRole();

        $this->actingAs($doctor)
            ->get(route('admin.departments.index'))
            ->assertForbidden()
            ->assertSee('Subscription ended');
    }

    public function test_admin_routes_are_open_on_a_trial_or_active_subscription(): void
    {
        $h = Hospital::factory()->create();
        $this->subscribe($h, SubscriptionStatus::Trialing, now()->addDays(10)->toDateTimeString());

        $this->actingAs($this->admin($h))->get(route('admin.departments.index'))->assertOk();
    }

    public function test_a_later_cancelled_row_does_not_block_a_still_active_subscription(): void
    {
        $h = Hospital::factory()->create();
        $this->subscribe($h, SubscriptionStatus::Active, now()->addYear()->toDateTimeString());
        // A newer, cancelled record (e.g. an aborted upgrade) must be ignored.
        Subscription::factory()->for($h)->for(Plan::factory())->create([
            'status' => SubscriptionStatus::Cancelled,
            'starts_at' => now(),
            'ends_at' => null,
        ]);

        $this->actingAs($this->admin($h))->get(route('admin.departments.index'))->assertOk();
    }

    public function test_api_routes_are_blocked_for_a_lapsed_tenant(): void
    {
        $h = Hospital::factory()->create();
        $this->subscribe($h, SubscriptionStatus::Expired, now()->subMonth()->toDateTimeString());
        $u = $this->admin($h);

        \Laravel\Sanctum\Sanctum::actingAs($u);
        $this->getJson('/api/v1/patients')->assertForbidden();
    }

    public function test_super_admin_is_never_gated(): void
    {
        $super = User::factory()->create(['hospital_id' => null, 'role' => 'super_admin', 'is_admin' => true]);
        $super->syncSpatieRole();

        $this->actingAs($super)->get(route('super.hospitals.index'))->assertOk();
    }

    public function test_gate_is_a_no_op_when_disabled_by_config(): void
    {
        config(['tenancy.enforce_subscription' => false]);
        $h = Hospital::factory()->create(); // no subscription at all

        $this->actingAs($this->admin($h))->get(route('admin.departments.index'))->assertOk();
    }

    public function test_access_gates_persist_onto_livewire_update_requests(): void
    {
        $persistent = app(PersistentMiddleware::class)->getPersistentMiddleware();

        foreach ([
            \App\Http\Middleware\IsAdmin::class,
            \App\Http\Middleware\IsSuperAdmin::class,
            \App\Http\Middleware\EnsureSubscribed::class,
            \Spatie\Permission\Middleware\PermissionMiddleware::class,
        ] as $class) {
            $this->assertContains($class, $persistent, "$class must re-run on every Livewire request");
        }
    }

    public function test_every_livewire_update_route_carries_the_web_group(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_ends_with($r->uri(), 'livewire/update'));

        $this->assertGreaterThanOrEqual(1, $routes->count());

        foreach ($routes as $route) {
            $this->assertContains('web', $route->gatherMiddleware(), $route->uri().' must carry the web middleware group');
        }
    }
}
