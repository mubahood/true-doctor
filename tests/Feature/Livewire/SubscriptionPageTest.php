<?php

namespace Tests\Feature\Livewire;

use App\Enums\SubscriptionStatus;
use App\Models\Hospital;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The subscription page itself: what it shows by default (history once there
 * IS a subscription, the "pick a plan" prompt when there isn't), how plainly
 * it states an expiry, and the two-step wizard that ends at Pesapal.
 */
class SubscriptionPageTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->hospital = Hospital::factory()->create();
        $admin = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin']);
        $admin->syncSpatieRole();
        $this->actingAs($admin);
        app(CurrentHospital::class)->set($this->hospital->id);
    }

    private function subscribe(SubscriptionStatus $status, ?string $endsAt = null, ?Plan $plan = null): Subscription
    {
        return Subscription::factory()->create([
            'hospital_id' => $this->hospital->id,
            'plan_id' => ($plan ?? Plan::factory()->create(['price' => '49.00', 'is_active' => true]))->id,
            'status' => $status,
            'starts_at' => now()->subDay(),
            'ends_at' => $endsAt,
            'trial_ends_at' => $status === SubscriptionStatus::Trialing ? $endsAt : null,
        ]);
    }

    // ── What the page leads with ──────────────────────────────────────

    public function test_with_no_subscription_it_leads_with_the_prompt_to_choose_a_plan(): void
    {
        Plan::factory()->create(['name' => 'Starter', 'is_active' => true]);

        Livewire::test(\App\Livewire\Subscription\Index::class)
            ->assertSee('Get your hospital set up with a plan')
            ->assertDontSee('Payment history')
            ->assertSee('Starter');
    }

    public function test_with_a_subscription_it_shows_the_payment_history_by_default(): void
    {
        $sub = $this->subscribe(SubscriptionStatus::Active, now()->addMonth()->toDateTimeString());
        $sub->payments()->create(['amount' => '49.00', 'method' => 'pesapal', 'reference' => 'SUB1-abc', 'paid_at' => now()]);

        Livewire::test(\App\Livewire\Subscription\Index::class)
            ->assertSee('Payment history')
            ->assertSee('SUB1-abc')
            ->assertSee('pesapal')
            ->assertDontSee('Get your hospital set up with a plan');
    }

    /** Prices are quoted — and charged — in shillings, with a USD reference beside them. */
    public function test_every_price_is_shown_in_shillings_with_a_usd_reference(): void
    {
        config()->set('services.pesapal.usd_to_ugx_rate', 3600);
        Plan::factory()->create(['name' => 'Starter', 'price' => '10000.00', 'is_active' => true]);

        Livewire::test(\App\Livewire\Subscription\Index::class)
            ->assertSee('USh 10,000')
            ->assertSee('$2.77');
    }

    /** What a hospital can pay with is stated on the card, not left to be discovered at the gateway. */
    public function test_the_payment_methods_are_shown_with_the_price(): void
    {
        Plan::factory()->create(['is_active' => true]);

        Livewire::test(\App\Livewire\Subscription\Index::class)
            ->assertSee('MTN')
            ->assertSee('airtel')
            ->assertSee('fa-cc-visa', false)
            ->assertSee('fa-cc-mastercard', false);
    }

    /** Plans are the point of the page; history is reference, so it comes after. */
    public function test_the_plans_come_before_the_payment_history(): void
    {
        $this->subscribe(SubscriptionStatus::Active, now()->addMonth()->toDateTimeString());
        Plan::factory()->create(['name' => 'Starter', 'is_active' => true]);

        $html = Livewire::test(\App\Livewire\Subscription\Index::class)->html();

        $this->assertLessThan(
            strpos($html, 'Payment history'),
            strpos($html, 'sub-plans'),
            'the plan cards must render above the payment history',
        );
    }

    /** A trial has bought nothing, so no card may claim to be the plan in force. */
    public function test_a_trialing_hospital_has_no_current_plan(): void
    {
        $plan = Plan::factory()->create(['name' => 'Starter', 'is_active' => true]);
        $this->subscribe(SubscriptionStatus::Trialing, now()->addDays(10)->toDateTimeString(), $plan);

        $component = Livewire::test(\App\Livewire\Subscription\Index::class);

        $this->assertNull($component->instance()->currentPlanId());
        $component->assertDontSee('Current plan')->assertSee('Subscribe');
    }

    public function test_an_active_hospital_does_have_a_current_plan(): void
    {
        $plan = Plan::factory()->create(['name' => 'Starter', 'is_active' => true]);
        $this->subscribe(SubscriptionStatus::Active, now()->addMonth()->toDateTimeString(), $plan);

        $component = Livewire::test(\App\Livewire\Subscription\Index::class);

        $this->assertSame($plan->id, $component->instance()->currentPlanId());
        $component->assertSee('Extend this plan');
    }

    // ── Expiry, stated plainly ────────────────────────────────────────

    public function test_an_expired_subscription_says_so_and_offers_a_renewal(): void
    {
        $plan = Plan::factory()->create(['name' => 'Starter', 'price' => '49.00', 'is_active' => true]);
        $this->subscribe(SubscriptionStatus::Expired, now()->subMonth()->toDateTimeString(), $plan);

        $component = Livewire::test(\App\Livewire\Subscription\Index::class);

        $this->assertTrue($component->instance()->isBlocked());
        $component->assertSee('Your subscription ended')->assertSee('Renew');
    }

    public function test_a_live_trial_counts_down_instead_of_warning(): void
    {
        $this->subscribe(SubscriptionStatus::Trialing, now()->addDays(10)->toDateTimeString());

        $component = Livewire::test(\App\Livewire\Subscription\Index::class);

        $this->assertFalse($component->instance()->isBlocked());
        $this->assertSame(10, $component->instance()->daysRemaining());
        $component->assertSee('days left');
    }

    /** The grace period EnsureSubscribed honours is the same one this page reflects. */
    public function test_a_subscription_inside_its_grace_period_is_not_treated_as_blocked(): void
    {
        config()->set('tenancy.subscription_grace_days', 3);
        $this->subscribe(SubscriptionStatus::Active, now()->subDay()->toDateTimeString());

        $this->assertFalse(Livewire::test(\App\Livewire\Subscription\Index::class)->instance()->isBlocked());
    }

    // ── The wizard ────────────────────────────────────────────────────

    /** The months a hospital picks drive the total, instantly. */
    public function test_the_wizard_total_follows_the_months_chosen(): void
    {
        $plan = Plan::factory()->create(['price' => '10000.00', 'is_active' => true]);

        $component = Livewire::test(\App\Livewire\Subscription\Index::class)
            ->call('openWizard', $plan->id)
            ->assertSet('months', 1);

        $this->assertSame('10000.00', $component->instance()->wizardTotal());
        $component->assertSee('USh 10,000');

        $component->set('months', 6);
        $this->assertSame('60000.00', $component->instance()->wizardTotal());
        $component->assertSee('USh 60,000');
    }

    public function test_the_months_are_clamped_to_what_checkout_will_accept(): void
    {
        $plan = Plan::factory()->create(['price' => '10000.00', 'is_active' => true]);

        Livewire::test(\App\Livewire\Subscription\Index::class)
            ->call('openWizard', $plan->id)
            ->set('months', 999)->assertSet('months', \App\Services\SubscriptionCheckoutService::MAX_MONTHS)
            ->set('months', 0)->assertSet('months', 1);
    }

    public function test_the_wizard_steps_forward_and_back_and_prefills_the_hospital_phone(): void
    {
        $this->hospital->update(['settings' => ['contact' => ['phone' => '+256700111222']]]);
        $plan = Plan::factory()->create(['is_active' => true]);

        Livewire::test(\App\Livewire\Subscription\Index::class)
            ->call('openWizard', $plan->id)
            ->assertSet('showWizard', true)
            ->assertSet('wizardStep', 1)
            ->assertSet('phone', '+256700111222')
            ->call('wizardNext')->assertSet('wizardStep', 2)
            ->call('wizardBack')->assertSet('wizardStep', 1)
            // Never past the last step.
            ->call('wizardNext')->call('wizardNext')->assertSet('wizardStep', 2);
    }

    public function test_staff_without_manage_settings_cannot_open_the_page(): void
    {
        $nurse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $nurse->syncSpatieRole();
        $this->actingAs($nurse);

        Livewire::test(\App\Livewire\Subscription\Index::class)->assertForbidden();
    }
}
