<?php

namespace Tests\Feature\Livewire;

use App\Models\Hospital;
use App\Models\InsuranceProvider;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BillingInsuranceIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function actingAccountant(Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'accountant']);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    private function stripped(Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'accountant']);
        $u->syncRoles([]);
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    public function test_invoices_index_renders_and_is_authorized(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAccountant($h);
        Livewire::test(\App\Livewire\Invoices\Index::class)->assertOk()->assertSee('Invoices');
    }

    public function test_invoices_index_forbidden_without_billing_view(): void
    {
        $h = Hospital::factory()->create();
        $this->stripped($h);
        Livewire::test(\App\Livewire\Invoices\Index::class)->assertForbidden();
    }

    public function test_claims_index_renders_and_is_authorized(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAccountant($h);
        Livewire::test(\App\Livewire\InsuranceClaims\Index::class)->assertOk()->assertSee('Insurance claims');
    }

    public function test_claims_index_forbidden_without_insurance_view(): void
    {
        $h = Hospital::factory()->create();
        $this->stripped($h);
        Livewire::test(\App\Livewire\InsuranceClaims\Index::class)->assertForbidden();
    }

    public function test_providers_index_searches_and_deletes(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAccountant($h);
        InsuranceProvider::factory()->create(['hospital_id' => $h->id, 'name' => 'Jubilee Health', 'code' => 'JUB']);
        $drop = InsuranceProvider::factory()->create(['hospital_id' => $h->id, 'name' => 'Prudential Care', 'code' => 'PRU']);

        Livewire::test(\App\Livewire\InsuranceProviders\Index::class)
            ->assertSee('Jubilee Health')
            ->set('search', 'Jubilee')
            ->assertSee('Jubilee Health')
            ->assertDontSee('Prudential Care')
            ->set('search', '')
            ->call('delete', $drop->id)
            ->assertHasNoErrors();

        $this->assertNull(InsuranceProvider::find($drop->id));
    }

    public function test_providers_index_forbidden_without_insurance_view(): void
    {
        $h = Hospital::factory()->create();
        $this->stripped($h);
        Livewire::test(\App\Livewire\InsuranceProviders\Index::class)->assertForbidden();
    }
}
