<?php

namespace Tests\Feature\Livewire;

use App\Models\Hospital;
use App\Models\Service;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SampleImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function actingAdmin(Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    public function test_open_lists_samples_and_import_creates_selected(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);

        $c = Livewire::test(\App\Livewire\Services\Index::class)
            ->call('openSamples')
            ->assertSet('showSamples', true);

        $this->assertNotEmpty($c->get('samples'));

        // Deselect all but the first, adjust its price, import.
        $samples = $c->get('samples');
        foreach ($samples as $i => $row) {
            $samples[$i]['selected'] = ($i === 0);
        }
        $samples[0]['price'] = 33;

        $c->set('samples', $samples)
            ->call('importSamples')
            ->assertHasNoErrors()
            ->assertSet('showSamples', false);

        $this->assertSame(1, Service::count());
        $svc = Service::first();
        $this->assertEquals(33, (float) $svc->price);
        $this->assertSame($h->id, $svc->hospital_id);
    }

    public function test_import_is_idempotent_and_skips_existing(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        // Pre-create a service matching one sample name.
        Service::factory()->create(['hospital_id' => $h->id, 'name' => 'General consultation']);

        $c = Livewire::test(\App\Livewire\Services\Index::class)->call('openSamples');
        // The pre-existing one is filtered out of the offered list.
        $names = array_map(fn ($r) => $r['name'], $c->get('samples'));
        $this->assertNotContains('General consultation', $names);

        $c->call('importSamples')->assertHasNoErrors();

        // No duplicate of the existing service.
        $this->assertSame(1, Service::where('name', 'General consultation')->count());
    }

    public function test_departments_import_creates_selected(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);

        $c = Livewire::test(\App\Livewire\Departments\Index::class)->call('openSamples')->assertSet('showSamples', true);
        $this->assertNotEmpty($c->get('samples'));

        $c->call('importSamples')->assertHasNoErrors()->assertSet('showSamples', false);

        $this->assertGreaterThan(0, \App\Models\Department::count());
        // Codes are upper-cased on import.
        $this->assertNotNull(\App\Models\Department::where('code', 'OPD')->first());
    }

    public function test_wards_import_creates_selected(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);

        Livewire::test(\App\Livewire\Wards\Index::class)
            ->call('openSamples')
            ->call('importSamples')
            ->assertHasNoErrors();

        $this->assertNotNull(\App\Models\Ward::where('name', 'General ward')->first());
    }

    public function test_import_forbidden_without_create_permission(): void
    {
        $h = Hospital::factory()->create();
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $u->syncRoles([]);
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        Livewire::test(\App\Livewire\Services\Index::class)->assertForbidden();
    }
}
