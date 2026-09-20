<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Models\Hospital;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * HMS_PLAN.md §2.1/§7 Phase 0 Step 3 — ResolveHospital and EnsureSubscribed.
 * Routes are registered ad hoc per test since no real tenant route exists
 * yet (Phase 1 onward); this proves the middleware in isolation.
 */
class TenancyMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enforce_subscription' => true]);

        Route::middleware(['web'])->get('/__test/current-hospital', fn () => response()->json([
            'hospital_id' => app(CurrentHospital::class)->id(),
        ]));

        Route::middleware(['web', 'subscribed'])->get('/__test/subscribed-only', fn () => response('ok'));
    }

    public function test_resolve_hospital_sets_context_from_the_authenticated_users_hospital_id(): void
    {
        $hospital = Hospital::factory()->create();
        $user = User::factory()->create(['hospital_id' => $hospital->id]);

        $this->actingAs($user)->getJson('/__test/current-hospital')
            ->assertJson(['hospital_id' => $hospital->id]);
    }

    public function test_super_admin_without_a_switch_has_no_hospital_context(): void
    {
        $user = User::factory()->create(['hospital_id' => null, 'role' => 'super_admin']);

        $this->actingAs($user)->getJson('/__test/current-hospital')
            ->assertJson(['hospital_id' => null]);
    }

    public function test_resolve_hospital_does_not_crash_on_sessionless_api_requests(): void
    {
        Route::middleware(['api'])->get('/__test/api-current-hospital', fn () => response()->json([
            'hospital_id' => app(CurrentHospital::class)->id(),
        ]));

        // Super-admin (null hospital) on the stateless API group: reading the
        // session switch-key would 500 without the hasSession() guard.
        $user = User::factory()->create(['hospital_id' => null, 'role' => 'super_admin']);

        $this->actingAs($user)->getJson('/__test/api-current-hospital')
            ->assertOk()
            ->assertJson(['hospital_id' => null]);
    }

    public function test_super_admin_can_switch_into_a_hospital_via_session(): void
    {
        $hospital = Hospital::factory()->create();
        $user = User::factory()->create(['hospital_id' => null, 'role' => 'super_admin']);

        $this->actingAs($user)
            ->withSession(['viewing_hospital_id' => $hospital->id])
            ->getJson('/__test/current-hospital')
            ->assertJson(['hospital_id' => $hospital->id]);
    }

    public function test_ensure_subscribed_allows_an_active_subscription(): void
    {
        $hospital = Hospital::factory()->create();
        Subscription::factory()->for($hospital)->for(Plan::factory())->create();
        $user = User::factory()->create(['hospital_id' => $hospital->id]);

        $this->actingAs($user)->get('/__test/subscribed-only')->assertOk();
    }

    public function test_ensure_subscribed_blocks_an_expired_subscription_past_grace(): void
    {
        $hospital = Hospital::factory()->create();
        Subscription::factory()->for($hospital)->for(Plan::factory())->expired()->create();
        $user = User::factory()->create(['hospital_id' => $hospital->id]);

        $this->actingAs($user)->get('/__test/subscribed-only')->assertForbidden();
    }

    public function test_ensure_subscribed_allows_within_the_grace_period(): void
    {
        config(['tenancy.subscription_grace_days' => 3]);
        $hospital = Hospital::factory()->create();
        Subscription::factory()->for($hospital)->for(Plan::factory())->create([
            'starts_at' => now()->subDays(35),
            'ends_at' => now()->subDay(), // lapsed yesterday, inside the 3-day grace window
            'status' => SubscriptionStatus::Active,
        ]);
        $user = User::factory()->create(['hospital_id' => $hospital->id]);

        $this->actingAs($user)->get('/__test/subscribed-only')->assertOk();
    }

    public function test_ensure_subscribed_blocks_once_past_the_grace_period(): void
    {
        config(['tenancy.subscription_grace_days' => 3]);
        $hospital = Hospital::factory()->create();
        Subscription::factory()->for($hospital)->for(Plan::factory())->create([
            'starts_at' => now()->subDays(35),
            'ends_at' => now()->subDays(10), // long past the 3-day grace window
            'status' => SubscriptionStatus::Active,
        ]);
        $user = User::factory()->create(['hospital_id' => $hospital->id]);

        $this->actingAs($user)->get('/__test/subscribed-only')->assertForbidden();
    }

    public function test_ensure_subscribed_blocks_a_hospital_with_no_subscription_at_all(): void
    {
        $hospital = Hospital::factory()->create();
        $user = User::factory()->create(['hospital_id' => $hospital->id]);

        $this->actingAs($user)->get('/__test/subscribed-only')->assertForbidden();
    }

    public function test_ensure_subscribed_is_a_no_op_without_a_hospital_context(): void
    {
        $user = User::factory()->create(['hospital_id' => null, 'role' => 'super_admin']);

        $this->actingAs($user)->get('/__test/subscribed-only')->assertOk();
    }
}
