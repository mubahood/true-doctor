<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Patients\Panels\Dependents;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\PatientDependent;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The dependents panel (ported from the retired PatientDependentTest, which
 * drove PatientDependentController over HTTP) plus the typeahead that replaced
 * "paste the dependent's UUID" (plan D6).
 */
class PatientDependentsPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function acting(Hospital $h, string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    private function panel(Patient $patient)
    {
        Livewire::withoutLazyLoading();

        return Livewire::test(Dependents::class, ['patientId' => $patient->id]);
    }

    public function test_link_and_unlink_a_dependent(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'hospital_admin');
        $guardian = Patient::factory()->create(['hospital_id' => $h->id]);
        $child = Patient::factory()->create(['hospital_id' => $h->id]);

        $this->panel($guardian)
            ->call('openLink')
            ->call('select', $child->uuid)
            ->set('relationship', 'child')
            ->call('link')
            ->assertHasNoErrors()
            ->assertSet('showLink', false);

        $link = PatientDependent::where('patient_id', $guardian->id)->firstOrFail();
        $this->assertSame($child->id, $link->dependent_patient_id);
        $this->assertSame('child', $link->relationship);

        $this->panel($guardian)->call('unlink', $link->id)->assertHasNoErrors();
        $this->assertDatabaseMissing('patient_dependents', ['id' => $link->id]);
    }

    public function test_cannot_link_a_patient_to_itself(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'hospital_admin');
        $p = Patient::factory()->create(['hospital_id' => $h->id]);

        $this->panel($p)
            ->call('openLink')
            ->set('dependent_uuid', $p->uuid)
            ->call('link')
            ->assertHasErrors('dependent_uuid');

        $this->assertDatabaseCount('patient_dependents', 0);
    }

    public function test_cannot_link_the_same_dependent_twice(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'hospital_admin');
        $guardian = Patient::factory()->create(['hospital_id' => $h->id]);
        $child = Patient::factory()->create(['hospital_id' => $h->id]);

        $this->panel($guardian)->call('select', $child->uuid)->call('link')->assertHasNoErrors();
        $this->panel($guardian)->call('select', $child->uuid)->call('link')->assertHasErrors('dependent_uuid');

        $this->assertDatabaseCount('patient_dependents', 1);
    }

    public function test_cannot_link_a_dependent_from_another_hospital(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $guardianA = Patient::factory()->create(['hospital_id' => $a->id]);
        $childB = Patient::factory()->create(['hospital_id' => $b->id]);

        // B's uuid is not resolvable in A's scope → the scoped exists rule fails.
        $this->acting($a, 'hospital_admin');
        $this->panel($guardianA)
            ->call('openLink')
            ->set('dependent_uuid', $childB->uuid)
            ->call('link')
            ->assertHasErrors('dependent_uuid');

        $this->assertDatabaseCount('patient_dependents', 0);
    }

    public function test_the_typeahead_is_tenant_scoped_and_excludes_the_patient_itself(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $guardian = Patient::factory()->create(['hospital_id' => $a->id, 'first_name' => 'Mary', 'last_name' => 'Searchme']);
        Patient::factory()->create(['hospital_id' => $a->id, 'first_name' => 'Joan', 'last_name' => 'Searchme']);
        Patient::factory()->create(['hospital_id' => $b->id, 'first_name' => 'Zeb', 'last_name' => 'Searchme']);

        $this->acting($a, 'hospital_admin');

        $this->panel($guardian)
            ->call('openLink')
            ->set('query', 'Searchme')
            ->assertSee('Joan Searchme')
            ->assertDontSee('Zeb Searchme');
    }

    public function test_the_typeahead_needs_at_least_two_characters(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'hospital_admin');
        $guardian = Patient::factory()->create(['hospital_id' => $h->id]);
        Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Ann', 'last_name' => 'Onlyone']);

        $this->panel($guardian)
            ->call('openLink')
            ->set('query', 'A')
            ->assertDontSee('Ann Onlyone');
    }

    public function test_a_role_without_patient_update_cannot_link(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'pharmacist'); // patients.view only
        $guardian = Patient::factory()->create(['hospital_id' => $h->id]);
        $child = Patient::factory()->create(['hospital_id' => $h->id]);

        $this->panel($guardian)->assertOk()->call('openLink')->assertForbidden();
        $this->panel($guardian)->set('dependent_uuid', $child->uuid)->call('link')->assertForbidden();

        $this->assertDatabaseCount('patient_dependents', 0);
    }

    public function test_a_patient_of_another_hospital_cannot_be_reached(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $patientB = Patient::factory()->create(['hospital_id' => $b->id]);

        $this->acting($a, 'hospital_admin');
        $this->expectException(ModelNotFoundException::class);
        $this->panel($patientB);
    }
}
