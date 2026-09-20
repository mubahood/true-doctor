<?php

namespace Tests\Feature;

use App\Livewire\Settings\Site;
use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The `settings` table is PLATFORM-GLOBAL (no hospital_id) — it drives the
 * public site name/tagline/verify rate-limit shared by every tenant. A tenant
 * hospital_admin must never be able to read or write it; only the SaaS operator
 * (super-admin) may. (Per-hospital config lives in Settings\Billing.)
 */
class GlobalSettingsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function make(string $role, ?int $hospitalId): User
    {
        $u = User::factory()->create(['role' => $role, 'hospital_id' => $hospitalId]);
        $u->syncSpatieRole();

        return $u;
    }

    public function test_hospital_admin_cannot_view_global_site_settings(): void
    {
        $admin = $this->make('hospital_admin', Hospital::factory()->create()->id);

        $this->actingAs($admin)->get('/admin/settings')->assertForbidden();
    }

    public function test_hospital_admin_cannot_write_global_site_settings(): void
    {
        $admin = $this->make('hospital_admin', Hospital::factory()->create()->id);

        Livewire::actingAs($admin)->test(Site::class)->assertForbidden();

        $this->assertDatabaseMissing('settings', ['value' => 'Hijacked']);
    }

    public function test_super_admin_can_manage_global_site_settings(): void
    {
        $super = $this->make('super_admin', null);

        $this->actingAs($super)->get('/admin/settings')->assertOk();

        Livewire::actingAs($super)->test(Site::class)
            ->set('site_name', 'True-Doctor')
            ->set('tagline', 'Care, connected')
            ->set('verify_rate_limit', 30)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $this->assertDatabaseHas('settings', ['key' => 'site_name', 'value' => 'True-Doctor']);
    }

    public function test_site_settings_validate(): void
    {
        $super = $this->make('super_admin', null);

        Livewire::actingAs($super)->test(Site::class)
            ->set('site_name', '')
            ->set('tagline', '')
            ->set('contact_email', 'not-an-email')
            ->set('verify_rate_limit', 0)
            ->call('save')
            ->assertHasErrors(['site_name', 'tagline', 'contact_email', 'verify_rate_limit']);
    }
}
