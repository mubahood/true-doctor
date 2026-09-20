<?php

namespace Tests\Feature;

use App\Livewire\Services\Index;
use App\Models\Hospital;
use App\Models\Service;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Price list behaviour through its only surface: the Livewire index +
 * slide-over (the classic controller/create/edit path was removed).
 */
class ServiceCatalogueTest extends TestCase
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

    public function test_admin_adds_a_service_to_the_price_list(): void
    {
        $h = Hospital::factory()->create();
        $this->actAs($h, 'hospital_admin');

        Livewire::test(Index::class)->call('create')
            ->set('name', 'Wound dressing')->set('price', '15.00')
            ->set('tax_exempt', false)->set('is_active', true)
            ->call('save')->assertHasNoErrors()->assertSet('showForm', false);

        $this->assertDatabaseHas('services', ['hospital_id' => $h->id, 'name' => 'Wound dressing', 'price' => '15.00']);
    }

    public function test_name_unique_per_hospital_reusable_across(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        Service::factory()->create(['hospital_id' => $a->id, 'name' => 'X-ray']);

        $this->actAs($a, 'hospital_admin');
        Livewire::test(Index::class)->call('create')->set('name', 'X-ray')->set('price', '1')
            ->call('save')->assertHasErrors('name');

        $this->actAs($b, 'hospital_admin');
        Livewire::test(Index::class)->call('create')->set('name', 'X-ray')->set('price', '1')
            ->call('save')->assertHasNoErrors();

        $this->assertDatabaseCount('services', 2);
    }

    public function test_role_without_services_manage_cannot_create(): void
    {
        $h = Hospital::factory()->create();
        $this->actAs($h, 'nurse');

        Livewire::test(Index::class)->call('create')->assertForbidden();
        $this->assertDatabaseCount('services', 0);
    }

    public function test_price_list_is_tenant_isolated(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $svcB = Service::factory()->create(['hospital_id' => $b->id, 'name' => 'SecretSvc']);
        $this->actAs($a, 'hospital_admin');

        $this->get(route('admin.services.index'))->assertOk()->assertDontSee('SecretSvc');

        // The global scope hides B's row from A entirely: edit resolves to "not found".
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        try {
            Livewire::test(Index::class)->call('edit', $svcB->id);
        } finally {
            $this->assertSame('SecretSvc', Service::withoutGlobalScopes()->find($svcB->id)->name);
        }
    }
}
