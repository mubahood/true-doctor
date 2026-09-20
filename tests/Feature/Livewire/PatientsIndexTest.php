<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Patients\Index;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PatientsIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function actingReceptionist(Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'receptionist']);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    public function test_it_lists_the_tenants_patients(): void
    {
        $h = Hospital::factory()->create();
        Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Alpha', 'last_name' => 'One']);
        $this->actingReceptionist($h);

        Livewire::test(Index::class)
            ->assertOk()
            ->assertSee('Alpha')
            ->assertSee('Register patient');
    }

    public function test_search_filters_the_list(): void
    {
        $h = Hospital::factory()->create();
        Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Findme', 'last_name' => 'Yes']);
        Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Hidden', 'last_name' => 'No']);
        $this->actingReceptionist($h);

        Livewire::test(Index::class)
            ->set('search', 'Findme')
            ->assertSee('Findme')
            ->assertDontSee('Hidden');
    }

    public function test_status_filter_works(): void
    {
        $h = Hospital::factory()->create();
        Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Keepme', 'status' => 'inactive']);
        Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Dropme', 'status' => 'active']);
        $this->actingReceptionist($h);

        Livewire::test(Index::class)
            ->set('statusFilter', 'inactive')
            ->assertSee('Keepme')
            ->assertDontSee('Dropme');
    }

    public function test_sorting_toggles_direction(): void
    {
        $h = Hospital::factory()->create();
        Patient::factory()->create(['hospital_id' => $h->id]);
        $this->actingReceptionist($h);

        Livewire::test(Index::class)
            ->call('sortBy', 'first_name')
            ->assertSet('sortField', 'first_name')
            ->assertSet('sortDir', 'asc')
            ->call('sortBy', 'first_name')
            ->assertSet('sortDir', 'desc');
    }

    public function test_sort_field_is_whitelisted_against_injection(): void
    {
        $h = Hospital::factory()->create();
        Patient::factory()->create(['hospital_id' => $h->id]);
        $this->actingReceptionist($h);

        // A non-whitelisted field is ignored (no SQL error, sortField unchanged).
        Livewire::test(Index::class)
            ->call('sortBy', 'password); drop table users;--')
            ->assertSet('sortField', '')
            ->assertOk();
    }

    public function test_a_user_without_patient_view_is_forbidden(): void
    {
        $h = Hospital::factory()->create();
        // Strip all roles → no patients.view permission (the create hook auto-syncs a role).
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'receptionist']);
        $u->syncRoles([]);
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        Livewire::test(Index::class)->assertForbidden();
    }

    public function test_modal_registers_a_patient_over_ajax(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        Livewire::test(Index::class)
            ->assertSet('showForm', false)
            ->call('create')
            ->assertSet('showForm', true)
            ->set('first_name', 'Amina')
            ->set('last_name', 'Okello')
            ->set('allergies', 'Penicillin, Peanuts')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $p = Patient::where('first_name', 'Amina')->first();
        $this->assertNotNull($p);
        $this->assertSame(['Penicillin', 'Peanuts'], $p->allergies);
        $this->assertNotEmpty($p->patient_no);
    }

    public function test_modal_edits_a_patient_and_keeps_number(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);
        $p = Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Before']);
        $no = $p->patient_no;

        Livewire::test(Index::class)
            ->call('edit', $p->id)
            ->assertSet('showForm', true)
            ->assertSet('first_name', 'Before')
            ->set('first_name', 'After')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('After', $p->fresh()->first_name);
        $this->assertSame($no, $p->fresh()->patient_no);
    }

    public function test_modal_validates_required_names(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        Livewire::test(Index::class)
            ->call('create')
            ->call('save')
            ->assertHasErrors(['first_name', 'last_name'])
            ->assertSet('showForm', true);
    }
}
