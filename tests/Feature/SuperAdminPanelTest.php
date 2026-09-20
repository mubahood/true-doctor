<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Livewire\Super\Hospitals\Index as HospitalsIndex;
use App\Livewire\Super\Plans\Index as PlansIndex;
use App\Livewire\Super\Subscriptions\Index as SubscriptionsIndex;
use App\Models\Hospital;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * HMS_PLAN.md §7 Phase 0 Step 5 — super-admin panel (hospitals, plans,
 * subscriptions, record payments). Route convention: /super/* is SaaS
 * central, gated to Super Admin only (§10).
 *
 * The three classic controllers were deleted in Phase 2: the panel is three
 * Livewire index pages with slide-overs, authorized by Hospital/Plan/
 * SubscriptionPolicy, so the write cases below drive the components.
 */
class SuperAdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create([
            'hospital_id' => null,
            'role' => 'super_admin',
            'is_admin' => true,
        ]);
    }

    private function hospitalAdmin(Hospital $hospital): User
    {
        return User::factory()->create([
            'hospital_id' => $hospital->id,
            'role' => 'hospital_admin',
        ]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/super/hospitals')->assertRedirect(route('admin.login'));
    }

    public function test_a_hospital_scoped_admin_is_forbidden(): void
    {
        $hospital = Hospital::factory()->create();
        $admin = $this->hospitalAdmin($hospital);

        $this->actingAs($admin)->get('/super/hospitals')->assertForbidden();
        $this->actingAs($admin)->get('/super/plans')->assertForbidden();
        $this->actingAs($admin)->get('/super/subscriptions')->assertForbidden();
    }

    public function test_super_admin_can_reach_every_panel_index(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get('/super/hospitals')->assertOk();
        $this->actingAs($admin)->get('/super/plans')->assertOk();
        $this->actingAs($admin)->get('/super/subscriptions')->assertOk();
    }

    public function test_super_admin_can_create_a_hospital(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(HospitalsIndex::class)
            ->call('create')
            ->set('name', 'Nakasero Hospital')
            ->set('timezone', 'Africa/Kampala')
            ->set('currency', 'UGX')
            ->set('status', 'active')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $this->assertDatabaseHas('hospitals', ['name' => 'Nakasero Hospital', 'slug' => 'nakasero-hospital']);
    }

    public function test_super_admin_can_create_a_plan_with_limits(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(PlansIndex::class)
            ->call('create')
            ->set('name', 'Growth')
            ->set('price', '400000')
            ->set('billing_cycle', 'monthly')
            ->set('max_staff', 25)
            ->set('max_patients', 5000)
            ->set('max_beds', 40)
            ->set('is_active', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $plan = Plan::where('name', 'Growth')->firstOrFail();
        $this->assertSame(25, $plan->limit('max_staff'));
        $this->assertSame(5000, $plan->limit('max_patients'));
        $this->assertSame(40, $plan->limit('max_beds'));
    }

    public function test_super_admin_can_create_a_subscription(): void
    {
        $this->actingAs($this->superAdmin());
        $hospital = Hospital::factory()->create();
        $plan = Plan::factory()->create();

        Livewire::test(SubscriptionsIndex::class)
            ->call('create')
            ->set('hospital_id', $hospital->id)
            ->set('plan_id', $plan->id)
            ->set('status', 'trialing')
            ->set('starts_at', now()->toDateString())
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $this->assertDatabaseHas('subscriptions', [
            'hospital_id' => $hospital->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
        ]);
    }

    public function test_recording_a_payment_extends_and_reactivates_a_lapsed_subscription(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $subscription = Subscription::factory()->expired()->create();

        Livewire::test(SubscriptionsIndex::class)
            ->call('recordPayment', $subscription->id)
            ->assertSet('showPayment', true)
            ->set('amount', '150000')
            ->set('method', 'manual')
            ->set('paid_at', now()->toDateString())
            ->set('extend_days', 30)
            ->call('savePayment')
            ->assertHasNoErrors()
            ->assertSet('showPayment', false);

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertTrue($subscription->ends_at->isFuture());
        $this->assertDatabaseHas('subscription_payments', [
            'subscription_id' => $subscription->id,
            'amount' => '150000.00',
            'recorded_by' => $admin->id,
        ]);
    }

    public function test_recording_a_payment_extends_from_the_current_end_date_when_still_active(): void
    {
        $this->actingAs($this->superAdmin());
        $subscription = Subscription::factory()->create([
            'status' => SubscriptionStatus::Active,
            'ends_at' => now()->addDays(10),
        ]);
        $expectedBase = $subscription->ends_at->copy();

        Livewire::test(SubscriptionsIndex::class)
            ->call('recordPayment', $subscription->id)
            ->set('amount', '150000')
            ->set('method', 'manual')
            ->set('paid_at', now()->toDateString())
            ->set('extend_days', 30)
            ->call('savePayment')
            ->assertHasNoErrors();

        $subscription->refresh();
        $this->assertEqualsWithDelta(
            $expectedBase->addDays(30)->timestamp,
            $subscription->ends_at->timestamp,
            5,
        );
    }

    public function test_recording_a_payment_validates_the_amount(): void
    {
        $this->actingAs($this->superAdmin());
        $subscription = Subscription::factory()->create();

        Livewire::test(SubscriptionsIndex::class)
            ->call('recordPayment', $subscription->id)
            ->set('amount', '0')
            ->call('savePayment')
            ->assertHasErrors(['amount'])
            ->assertSet('showPayment', true);

        $this->assertDatabaseCount('subscription_payments', 0);
    }

    public function test_a_hospital_scoped_admin_cannot_drive_the_super_components(): void
    {
        $hospital = Hospital::factory()->create();
        $this->actingAs($this->hospitalAdmin($hospital));

        Livewire::test(HospitalsIndex::class)->assertForbidden();
        Livewire::test(PlansIndex::class)->assertForbidden();
        Livewire::test(SubscriptionsIndex::class)->assertForbidden();
    }
}
