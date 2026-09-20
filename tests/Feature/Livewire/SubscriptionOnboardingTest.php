<?php

namespace Tests\Feature\Livewire;

use App\Models\Department;
use App\Models\Hospital;
use App\Models\Plan;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SubscriptionOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function staff(string $role, ?Hospital $hospital = null): User
    {
        $hospital ??= Hospital::factory()->create();
        $u = User::factory()->create(['hospital_id' => $hospital->id, 'role' => $role]);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($hospital->id);

        return $u;
    }

    // ── Subscription ────────────────────────────────────────────
    public function test_the_owner_sees_the_plans_and_the_current_subscription(): void
    {
        $h = Hospital::factory()->create();
        $this->staff('hospital_admin', $h);
        $plan = Plan::factory()->create(['name' => 'Starter', 'price' => '149.00', 'is_active' => true]);
        Plan::factory()->create(['name' => 'Hidden', 'is_active' => false]);
        Subscription::factory()->create(['hospital_id' => $h->id, 'plan_id' => $plan->id, 'status' => 'trialing']);

        Livewire::test(\App\Livewire\Subscription\Index::class)
            ->assertOk()
            ->assertSee('Subscription')
            ->assertSee('Starter')
            ->assertSee('Trialing')
            ->assertDontSee('Hidden');
    }

    public function test_the_subscription_page_renders_over_http(): void
    {
        $h = Hospital::factory()->create();
        $this->staff('hospital_admin', $h);
        Plan::factory()->create(['is_active' => true]);

        $this->get('/admin/subscription')->assertOk()->assertSee('Choose a plan');
    }

    /**
     * The wizard's own steps are pure Livewire; only its final step is a real
     * form — that one legitimately ends in the external Pesapal redirect.
     */
    public function test_the_wizards_final_step_is_a_classic_post_form_to_checkout(): void
    {
        $this->staff('hospital_admin');
        $plan = Plan::factory()->create(['is_active' => true]);

        $html = Livewire::test(\App\Livewire\Subscription\Index::class)
            ->call('openWizard', $plan->id)
            ->call('wizardNext')
            ->assertSet('wizardStep', 2)
            ->html();

        $this->assertStringContainsString(route('admin.subscription.checkout', $plan), $html);
    }

    public function test_a_nurse_cannot_view_the_subscription_page(): void
    {
        $this->staff('nurse');

        Livewire::test(\App\Livewire\Subscription\Index::class)->assertForbidden();
        $this->get('/admin/subscription')->assertForbidden();
    }

    public function test_the_subscription_page_never_shows_another_tenants_subscription(): void
    {
        $mine = Hospital::factory()->create();
        $other = Hospital::factory()->create();
        $otherPlan = Plan::factory()->create(['name' => 'Foreign Plan', 'is_active' => false]);
        Subscription::factory()->create(['hospital_id' => $other->id, 'plan_id' => $otherPlan->id, 'status' => 'active']);

        $this->staff('hospital_admin', $mine);

        Livewire::test(\App\Livewire\Subscription\Index::class)
            ->assertOk()
            ->assertSee('Get your hospital set up with a plan')
            ->assertDontSee('Foreign Plan');
    }

    // ── Onboarding ──────────────────────────────────────────────
    public function test_the_wizard_reflects_what_is_already_set_up(): void
    {
        $h = Hospital::factory()->create([
            'address' => 'Plot 1',
            'settings' => [
                'contact' => ['phone' => '+256700000000'],
                'billing' => ['currency_code' => 'UGX', 'invoice_prefix' => 'INV'],
            ],
        ]);
        $this->staff('hospital_admin', $h);
        Service::factory()->create(['hospital_id' => $h->id, 'is_active' => true]);

        // Profile, billing and the price list are done; departments and staff are not.
        Livewire::test(\App\Livewire\Onboarding\Index::class)
            ->assertOk()
            ->assertSee('3 of 5')
            ->assertSee('Departments')
            ->assertSee('Your team')
            ->assertSee('Still needed');
    }

    /**
     * Every required step used to also link to its full module page ("Open the
     * full departments page"), right below the inline form that already did
     * the same job — two ways to do one thing, confusing rather than helpful.
     * Now every step is self-contained: nothing inside a step's body navigates
     * away from the wizard at all.
     */
    public function test_no_step_offers_a_redundant_link_to_its_own_full_module_page(): void
    {
        $html = Livewire::actingAs($this->staff('hospital_admin'))
            ->test(\App\Livewire\Onboarding\Index::class)
            ->html();

        $this->assertStringNotContainsString('Open the full', $html);
    }

    /** Whatever links the wizard DOES still offer must still follow the house rule. */
    public function test_links_the_wizard_still_offers_navigate_without_a_reload(): void
    {
        $h = Hospital::factory()->create();
        $this->staff('hospital_admin', $h);
        Subscription::factory()->create(['hospital_id' => $h->id, 'plan_id' => Plan::factory()->create()->id, 'status' => 'trialing']);

        $html = Livewire::test(\App\Livewire\Onboarding\Index::class)->html();

        $this->assertMatchesRegularExpression(
            '/<a[^>]*wire:navigate[^>]*href="[^"]*\/admin\/subscription"|<a[^>]*href="[^"]*\/admin\/subscription"[^>]*wire:navigate/',
            $html,
            'The "View plans" link must be a wire:navigate link.',
        );
    }

    public function test_a_completed_wizard_offers_the_way_out(): void
    {
        $h = Hospital::factory()->create([
            'address' => 'Plot 1',
            'settings' => [
                'contact' => ['phone' => '+256700000000'],
                'billing' => ['currency_code' => 'UGX', 'invoice_prefix' => 'INV'],
            ],
        ]);
        $this->staff('hospital_admin', $h);
        Service::factory()->create(['hospital_id' => $h->id, 'is_active' => true]);
        Department::factory()->create(['hospital_id' => $h->id, 'is_active' => true]);
        User::factory()->create(['hospital_id' => $h->id, 'role' => 'nurse', 'is_active' => true]);

        Livewire::test(\App\Livewire\Onboarding\Index::class)
            ->assertSee('5 of 5')
            ->assertSee('Your hospital is ready')
            ->call('finish')
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_the_wizard_renders_over_http(): void
    {
        $this->staff('hospital_admin');

        $this->get('/admin/onboarding')->assertOk()->assertSee('Finish setting up your hospital');
    }
}
