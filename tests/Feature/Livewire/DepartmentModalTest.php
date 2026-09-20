<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Departments\Index;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DepartmentModalTest extends TestCase
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

    public function test_create_opens_modal_and_persists_over_ajax(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);

        Livewire::test(Index::class)
            ->assertSet('showForm', false)
            ->call('create')
            ->assertSet('showForm', true)
            ->set('name', 'Radiology Dept')
            ->set('code', 'rad')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $d = Department::where('name', 'Radiology Dept')->first();
        $this->assertNotNull($d);
        $this->assertSame($h->id, $d->hospital_id);
        $this->assertSame('RAD', $d->code); // upper-cased
    }

    public function test_edit_prefills_and_updates(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        $d = Department::factory()->create(['hospital_id' => $h->id, 'name' => 'Old Name']);

        Livewire::test(Index::class)
            ->call('edit', $d->id)
            ->assertSet('showForm', true)
            ->assertSet('name', 'Old Name')
            ->set('name', 'New Name')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('New Name', $d->fresh()->name);
    }

    public function test_validation_blocks_empty_name(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);

        Livewire::test(Index::class)
            ->call('create')
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name' => 'required'])
            ->assertSet('showForm', true);
    }

    public function test_duplicate_name_rejected_but_self_allowed_on_edit(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        Department::factory()->create(['hospital_id' => $h->id, 'name' => 'Cardiology']);
        $edit = Department::factory()->create(['hospital_id' => $h->id, 'name' => 'Neurology']);

        // New with a taken name is rejected.
        Livewire::test(Index::class)
            ->call('create')
            ->set('name', 'Cardiology')
            ->call('save')
            ->assertHasErrors('name');

        // Editing a record and keeping its own name is allowed.
        Livewire::test(Index::class)
            ->call('edit', $edit->id)
            ->set('name', 'Neurology')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_create_forbidden_without_permission(): void
    {
        $h = Hospital::factory()->create();
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $u->syncRoles([]);
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        Livewire::test(Index::class)->assertForbidden();
    }
}
