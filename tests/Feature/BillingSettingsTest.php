<?php

namespace Tests\Feature;

use App\Livewire\Settings\Billing;
use App\Models\Hospital;
use App\Models\User;
use App\Support\CurrentHospital;
use App\Support\HospitalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BillingSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    public function test_admin_configures_currency_and_it_persists(): void
    {
        $h = Hospital::factory()->create(['currency' => 'USD']);
        $admin = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $admin->syncSpatieRole();
        $this->actingAs($admin);
        app(CurrentHospital::class)->set($h->id);

        Livewire::test(Billing::class)
            ->set('currency_code', 'kes')
            ->set('currency_symbol', 'KSh')
            ->set('currency_position', 'before')
            ->set('decimals', 0)
            ->set('thousands_separator', ',')
            ->set('decimal_separator', '.')
            ->set('tax_enabled', true)
            ->set('tax_label', 'VAT')
            ->set('tax_rate', '16')
            ->set('consultation_fee', '500')
            ->set('invoice_prefix', 'INV')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $h->refresh();
        $this->assertSame('KES', $h->currency);
        $this->assertSame('KSh', $h->settings['billing']['currency_symbol']);

        app(CurrentHospital::class)->set($h->id);
        $this->assertSame('KSh1,500', app(HospitalSettings::class)->format('1500'));
    }

    public function test_invalid_billing_input_is_rejected(): void
    {
        $h = Hospital::factory()->create(['currency' => 'USD']);
        $admin = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $admin->syncSpatieRole();
        $this->actingAs($admin);
        app(CurrentHospital::class)->set($h->id);

        Livewire::test(Billing::class)
            ->set('currency_code', '1')
            ->set('decimals', 7)
            ->set('invoice_prefix', '')
            ->call('save')
            ->assertHasErrors(['currency_code', 'decimals', 'invoice_prefix']);

        $this->assertSame('USD', $h->fresh()->currency);
    }

    public function test_the_money_preview_updates_live_with_the_format(): void
    {
        $h = Hospital::factory()->create(['currency' => 'USD']);
        $admin = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $admin->syncSpatieRole();
        $this->actingAs($admin);
        app(CurrentHospital::class)->set($h->id);

        Livewire::test(Billing::class)
            ->set('currency_symbol', '$')
            ->set('currency_position', 'before')
            ->set('decimals', 2)
            ->set('thousands_separator', ',')
            ->set('decimal_separator', '.')
            ->assertSee('$1,234,567.89')
            ->set('currency_position', 'after')
            ->set('decimals', 0)
            ->set('thousands_separator', ' ')
            ->assertSee('1 234 568 $');
    }

    public function test_non_admin_cannot_change_billing_settings(): void
    {
        $h = Hospital::factory()->create();
        $nurse = User::factory()->create(['hospital_id' => $h->id, 'role' => 'nurse']);
        $nurse->syncSpatieRole();

        $this->actingAs($nurse)->get('/admin/settings/billing')->assertForbidden();

        Livewire::actingAs($nurse)->test(Billing::class)->assertForbidden();
    }
}
