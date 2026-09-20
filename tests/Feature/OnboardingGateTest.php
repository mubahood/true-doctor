<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Hospital;
use App\Models\Service;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OnboardingGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        // The gate is off by default in tests; this suite exercises it explicitly.
        config(['onboarding.gate_enabled' => true]);
    }

    private function admin(Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $u->syncSpatieRole();

        return $u;
    }

    private function completeOnboarding(Hospital $h): void
    {
        $h->update([
            'address' => 'Plot 1, Kampala Road',
            'settings' => [
                'contact' => ['phone' => '+256700000000', 'email' => 'info@example.test'],
                'billing' => ['currency_code' => 'UGX', 'invoice_prefix' => 'INV'],
            ],
        ]);
        app(CurrentHospital::class)->set($h->id);
        Service::factory()->create(['hospital_id' => $h->id, 'is_active' => true]);
        Department::factory()->create(['hospital_id' => $h->id, 'is_active' => true]);
        // At least one staff member beyond the founding admin created by admin().
        User::factory()->create(['hospital_id' => $h->id, 'role' => 'nurse', 'is_active' => true]);
    }

    public function test_admin_with_incomplete_setup_is_redirected_to_onboarding(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAs($this->admin($h))
            ->get('/admin/patients')
            ->assertRedirect(route('admin.onboarding'));
    }

    public function test_the_dashboard_also_redirects_when_incomplete(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAs($this->admin($h))
            ->get('/admin')
            ->assertRedirect(route('admin.onboarding'));
    }

    public function test_the_onboarding_page_and_setup_pages_stay_reachable(): void
    {
        $h = Hospital::factory()->create();
        $admin = $this->admin($h);

        // No redirect loop: the checklist itself is reachable.
        $this->actingAs($admin)->get('/admin/onboarding')->assertOk();
        // Every page needed to COMPLETE onboarding must be reachable.
        $this->actingAs($admin)->get('/admin/settings/billing')->assertOk();
        $this->actingAs($admin)->get('/admin/services')->assertOk();
        $this->actingAs($admin)->get('/admin/departments')->assertOk();
        $this->actingAs($admin)->get('/admin/users')->assertOk();
    }

    public function test_missing_staff_still_redirects(): void
    {
        // Billing + price list + department done, but no staff invited yet.
        $h = Hospital::factory()->create(['settings' => ['billing' => ['currency_code' => 'UGX']]]);
        app(CurrentHospital::class)->set($h->id);
        Service::factory()->create(['hospital_id' => $h->id]);
        Department::factory()->create(['hospital_id' => $h->id]);

        $this->actingAs($this->admin($h))
            ->get('/admin')
            ->assertRedirect(route('admin.onboarding'));
    }

    public function test_a_trial_hospital_that_finished_setup_is_not_gated(): void
    {
        // Fully set up (billing + price list + department + staff) but still on
        // a free trial with NO paid subscription — must NOT be trapped behind the
        // paywall; the gate lets them in (trial expiry is EnsureSubscribed's job).
        $h = Hospital::factory()->create();
        $this->completeOnboarding($h);
        \App\Models\Subscription::factory()->for($h)->for(\App\Models\Plan::factory())->create([
            'status' => \App\Enums\SubscriptionStatus::Trialing,
        ]);

        $this->actingAs($this->admin($h))
            ->get('/admin')
            ->assertOk();
    }

    public function test_completed_setup_lifts_the_gate(): void
    {
        $h = Hospital::factory()->create();
        $this->completeOnboarding($h);

        $this->actingAs($this->admin($h))
            ->get('/admin/patients')
            ->assertOk();
    }

    public function test_setup_cannot_be_skipped(): void
    {
        $h = Hospital::factory()->create();
        $admin = $this->admin($h);

        // There is no skip action any more, and nothing a caller can set to
        // bypass the gate: the wizard is the only way out.
        $this->assertFalse(method_exists(\App\Livewire\Onboarding\Index::class, 'skip'));

        session()->put('onboarding_skipped', true);   // the retired flag is ignored
        $this->actingAs($admin)->get('/admin/patients')->assertRedirect(route('admin.onboarding'));
    }

    public function test_finishing_is_refused_while_a_required_step_is_outstanding(): void
    {
        $h = Hospital::factory()->create();
        $admin = $this->admin($h);
        app(CurrentHospital::class)->set($h->id);

        Livewire::actingAs($admin)->test(\App\Livewire\Onboarding\Index::class)
            ->call('finish')
            ->assertNoRedirect()
            ->assertDispatched('toast');

        $this->actingAs($admin)->get('/admin/patients')->assertRedirect(route('admin.onboarding'));
    }

    public function test_non_admin_staff_are_never_gated(): void
    {
        $h = Hospital::factory()->create(); // incomplete
        $doctor = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor']);
        $doctor->syncSpatieRole();

        // Doctor can view patients and must NOT be funnelled through onboarding.
        $this->actingAs($doctor)->get('/admin/patients')->assertOk();
    }

    public function test_gate_can_be_disabled_by_config(): void
    {
        config(['onboarding.gate_enabled' => false]);
        $h = Hospital::factory()->create(); // incomplete

        $this->actingAs($this->admin($h))->get('/admin/patients')->assertOk();
    }

    // ── The sidebar follows the gate ──────────────────────────────────────
    // A menu full of links that bounce back to the wizard is a menu of dead
    // ends, so while setup is outstanding it collapses to the one section that
    // still works.

    public function test_the_sidebar_shows_only_the_configuration_section_during_setup(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAs($this->admin($h));

        $sidebar = $this->sidebar();

        // The one section that works, with the pages the steps need.
        $this->assertStringContainsString('Configuration', $sidebar);
        $this->assertStringContainsString('Set up your hospital', $sidebar);
        $this->assertStringContainsString('Departments', $sidebar);
        $this->assertStringContainsString('Price list', $sidebar);

        // Everything the gate would bounce is gone.
        foreach (['Patient care', 'Patients', 'Appointments', 'Invoices', 'Lab orders', 'Inpatient'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $sidebar, "\"{$hidden}\" must not be offered during setup");
        }

        // And the admin is told why the menu is short, with how far they are.
        $this->assertStringContainsString('0 of 5 complete', $sidebar);
    }

    public function test_every_link_the_sidebar_offers_during_setup_actually_opens(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAs($this->admin($h));

        $links = $this->sidebarLinks();
        $this->assertNotEmpty($links);

        foreach ($links as $link) {
            $this->get($link)->assertOk();   // never a bounce back to the wizard
        }
    }

    public function test_the_full_menu_returns_once_setup_is_complete(): void
    {
        $h = Hospital::factory()->create();
        $this->completeOnboarding($h);
        $this->actingAs($this->admin($h));

        $sidebar = $this->sidebar('/admin');

        $this->assertStringContainsString('Patients', $sidebar);
        $this->assertStringContainsString('Patient care', $sidebar);
        $this->assertStringContainsString('Administration', $sidebar);
    }

    public function test_other_staff_keep_the_full_menu_at_an_unconfigured_hospital(): void
    {
        $h = Hospital::factory()->create(); // incomplete
        $doctor = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor']);
        $doctor->syncSpatieRole();

        $this->actingAs($doctor);

        $this->assertStringContainsString('Patients', $this->sidebar('/admin/patients'));
    }

    public function test_the_setup_sidebar_is_persisted_under_its_own_key(): void
    {
        // Otherwise the restricted menu would survive wire:navigate and stay on
        // screen after setup is finished. A different persist key means the full
        // menu renders on the very next navigation.
        $h = Hospital::factory()->create();
        $admin = $this->admin($h);

        $this->actingAs($admin)->get('/admin/onboarding')->assertSee('x-persist="sidebar-setup"', false);

        $this->completeOnboarding($h);
        $this->actingAs($admin)->get('/admin/onboarding')->assertSee('x-persist="sidebar"', false);
    }

    /** The rendered sidebar only — the page body mentions plenty of pages it does not link to. */
    private function sidebar(string $url = '/admin/onboarding'): string
    {
        $html = $this->get($url)->assertOk()->getContent();
        preg_match('#<aside class="tb-sidebar".*?</aside>#s', (string) $html, $m);

        $this->assertNotEmpty($m, 'the sidebar did not render');

        return $m[0];
    }

    /** @return list<string> */
    private function sidebarLinks(string $url = '/admin/onboarding'): array
    {
        preg_match_all('/href="([^"]+)"/', $this->sidebar($url), $m);

        return array_values(array_unique($m[1]));
    }
}
