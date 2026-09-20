<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Models\Hospital;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CurrentHospital;
use App\Support\SubscriptionState;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The header badge, and the SubscriptionState behind it — the same object the
 * gate and the subscription page read, so what the admin is told in the header
 * can never contradict what the gate does on the next click.
 */
class SubscriptionBadgeTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);
    }

    private function actingAsRole(string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => $role]);
        $u->syncSpatieRole();
        $this->actingAs($u);

        return $u;
    }

    private function subscribe(SubscriptionStatus $status, ?string $endsAt): Subscription
    {
        return Subscription::factory()->create([
            'hospital_id' => $this->hospital->id,
            'plan_id' => Plan::factory()->create(['is_active' => true])->id,
            'status' => $status,
            'starts_at' => now()->subDay(),
            'ends_at' => $endsAt,
            'trial_ends_at' => $status === SubscriptionStatus::Trialing ? $endsAt : null,
        ]);
    }

    private function badge(): ?\App\Support\Subscription\Badge
    {
        return app(SubscriptionState::class)->badge($this->hospital->fresh());
    }

    // ── What it says ──────────────────────────────────────────────────

    public function test_a_hospital_that_has_never_subscribed_is_invited_to(): void
    {
        $badge = $this->badge();

        $this->assertSame('Subscribe now', $badge->label);
        $this->assertSame('primary', $badge->tone);
        $this->assertTrue($badge->urgent);
    }

    public function test_a_comfortable_trial_counts_down_without_alarming(): void
    {
        $this->subscribe(SubscriptionStatus::Trialing, now()->addDays(14)->toDateTimeString());

        $badge = $this->badge();

        $this->assertSame('Trial · 14 days left', $badge->label);
        $this->assertSame('warn', $badge->tone);
        $this->assertFalse($badge->urgent);
        $this->assertStringContainsString('Pick a plan', $badge->nudge);
    }

    public function test_a_trial_in_its_last_week_pushes_harder(): void
    {
        $this->subscribe(SubscriptionStatus::Trialing, now()->addDays(3)->toDateTimeString());

        $badge = $this->badge();

        $this->assertSame('Trial ends in 3 days', $badge->label);
        $this->assertSame('danger', $badge->tone);
        $this->assertTrue($badge->urgent);
    }

    public function test_an_ended_trial_says_so(): void
    {
        $this->subscribe(SubscriptionStatus::Trialing, now()->subWeek()->toDateTimeString());

        $badge = $this->badge();

        $this->assertSame('Trial ended', $badge->label);
        $this->assertSame('danger', $badge->tone);
    }

    public function test_a_healthy_paid_subscription_is_quiet(): void
    {
        $this->subscribe(SubscriptionStatus::Active, now()->addMonths(3)->toDateTimeString());

        $badge = $this->badge();

        $this->assertStringEndsWith('days left', $badge->label);
        $this->assertSame('ok', $badge->tone);
        $this->assertFalse($badge->urgent);
    }

    public function test_a_paid_subscription_near_its_end_asks_for_a_renewal(): void
    {
        $this->subscribe(SubscriptionStatus::Active, now()->addDays(9)->toDateTimeString());

        $badge = $this->badge();

        $this->assertSame('Renew · 9 days left', $badge->label);
        $this->assertSame('warn', $badge->tone);
        $this->assertTrue($badge->urgent);
    }

    public function test_an_expired_subscription_says_so(): void
    {
        $this->subscribe(SubscriptionStatus::Expired, now()->subMonth()->toDateTimeString());

        $this->assertSame('Subscription ended', $this->badge()->label);
    }

    public function test_a_day_is_singular(): void
    {
        $this->subscribe(SubscriptionStatus::Trialing, now()->addHours(20)->toDateTimeString());

        $this->assertSame('Trial ends in 1 day', $this->badge()->label);
    }

    // ── Where it shows ────────────────────────────────────────────────

    public function test_the_admin_sees_it_in_the_header(): void
    {
        $this->subscribe(SubscriptionStatus::Trialing, now()->addDays(14)->toDateTimeString());
        $this->actingAsRole('hospital_admin');

        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Trial · 14 days left')
            ->assertSee('sub-badge', false);
    }

    /** Staff who cannot subscribe would only be nagged about something they cannot fix. */
    public function test_other_staff_are_not_shown_it(): void
    {
        $this->subscribe(SubscriptionStatus::Trialing, now()->addDays(14)->toDateTimeString());
        $this->actingAsRole('doctor');

        $this->get(route('admin.patients.index'))
            ->assertOk()
            ->assertDontSee('Trial · 14 days left')
            ->assertDontSee('sub-badge', false);
    }

    public function test_it_links_to_the_page_that_acts_on_it(): void
    {
        $this->actingAsRole('hospital_admin');

        $this->get(route('admin.subscription.index'))
            ->assertOk()
            ->assertSee('Subscribe now')
            ->assertSee(route('admin.subscription.index'), false);
    }

    // ── One source of truth ───────────────────────────────────────────

    /** The badge and the gate must never disagree about whether the hospital is in. */
    public function test_the_badge_and_the_gate_read_the_same_state(): void
    {
        config(['tenancy.enforce_subscription' => true]);
        $this->subscribe(SubscriptionStatus::Expired, now()->subMonth()->toDateTimeString());
        $this->actingAsRole('hospital_admin');

        $state = app(SubscriptionState::class);
        $this->assertFalse($state->grantsAccess($this->hospital));
        $this->assertSame('danger', $state->badge($this->hospital)->tone);

        // …and the gate agrees, by bouncing them to the page the badge points at.
        $this->get(route('admin.departments.index'))->assertRedirect(route('admin.subscription.index'));
    }

    /** The grace period keeps a just-lapsed hospital in, and the badge must not cry wolf. */
    public function test_a_subscription_inside_its_grace_period_is_still_in(): void
    {
        config(['tenancy.subscription_grace_days' => 3]);
        $this->subscribe(SubscriptionStatus::Active, now()->subDay()->toDateTimeString());

        $this->assertTrue(app(SubscriptionState::class)->grantsAccess($this->hospital));
    }
}
