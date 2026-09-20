<?php

namespace Tests\Feature;

use App\Livewire\InsuranceProviders\Index;
use App\Models\Hospital;
use App\Models\InsuranceProvider;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Insurance providers through their only surface: the Livewire index +
 * slide-over (the classic controller/create/edit path was removed).
 */
class InsuranceProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function actAs(Hospital $h, string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    public function test_admin_creates_a_provider(): void
    {
        $h = Hospital::factory()->create();
        $this->actAs($h, 'hospital_admin');

        Livewire::test(Index::class)->call('create')
            ->set('name', 'Jubilee Health')->set('code', 'JUB')
            ->set('contact_email', 'claims@jubilee.test')->set('is_active', true)
            ->call('save')->assertHasNoErrors()->assertSet('showForm', false);

        $this->assertDatabaseHas('insurance_providers', [
            'hospital_id' => $h->id, 'name' => 'Jubilee Health', 'code' => 'JUB',
        ]);
    }

    public function test_name_unique_per_hospital_reusable_across(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        InsuranceProvider::factory()->create(['hospital_id' => $a->id, 'name' => 'Britam']);

        $this->actAs($a, 'hospital_admin');
        Livewire::test(Index::class)->call('create')->set('name', 'Britam')->call('save')->assertHasErrors('name');

        $this->actAs($b, 'hospital_admin');
        Livewire::test(Index::class)->call('create')->set('name', 'Britam')->call('save')->assertHasNoErrors();

        $this->assertDatabaseCount('insurance_providers', 2);
    }

    public function test_invalid_contact_email_is_rejected(): void
    {
        $h = Hospital::factory()->create();
        $this->actAs($h, 'hospital_admin');

        Livewire::test(Index::class)->call('create')
            ->set('name', 'Bad Contact')->set('contact_email', 'not-an-email')
            ->call('save')->assertHasErrors('contact_email');
    }

    public function test_a_role_without_insurance_manage_cannot_create(): void
    {
        $h = Hospital::factory()->create();
        $this->actAs($h, 'receptionist');

        Livewire::test(Index::class)->call('create')->assertForbidden();
        $this->assertDatabaseCount('insurance_providers', 0);
    }

    public function test_providers_are_tenant_isolated(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $providerB = InsuranceProvider::factory()->create(['hospital_id' => $b->id, 'name' => 'SecretInsurer']);
        $this->actAs($a, 'hospital_admin');

        $this->get(route('admin.insurance-providers.index'))->assertOk()->assertDontSee('SecretInsurer');

        // The global scope hides B's row from A entirely: edit resolves to "not found".
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        try {
            Livewire::test(Index::class)->call('edit', $providerB->id);
        } finally {
            $this->assertSame('SecretInsurer', InsuranceProvider::withoutGlobalScopes()->find($providerB->id)->name);
        }
    }
}
