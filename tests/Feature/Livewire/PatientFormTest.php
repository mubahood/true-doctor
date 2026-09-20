<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Patients\Form;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PatientFormTest extends TestCase
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

    public function test_it_registers_a_patient_and_allocates_a_number(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        Livewire::test(Form::class)
            ->set('first_name', 'Grace')
            ->set('last_name', 'Nakato')
            ->set('allergies', 'Penicillin, Peanuts')
            ->set('consent_given', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $p = Patient::where('first_name', 'Grace')->first();
        $this->assertNotNull($p);
        $this->assertSame($h->id, $p->hospital_id);
        $this->assertNotEmpty($p->patient_no);
        $this->assertSame(['Penicillin', 'Peanuts'], $p->allergies);
        $this->assertTrue((bool) $p->consent_given);
    }

    public function test_validation_rejects_a_patient_with_no_name(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        Livewire::test(Form::class)
            ->set('first_name', '')
            ->set('last_name', '')
            ->call('save')
            ->assertHasErrors(['first_name', 'last_name']);
    }

    public function test_it_edits_an_existing_patient(): void
    {
        $h = Hospital::factory()->create();
        $p = Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Old', 'last_name' => 'Name']);
        $this->actingReceptionist($h);

        Livewire::test(Form::class, ['patient' => $p])
            ->assertSet('first_name', 'Old')
            ->set('first_name', 'New')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertSame('New', $p->fresh()->first_name);
    }

    public function test_edit_preserves_the_patient_number(): void
    {
        $h = Hospital::factory()->create();
        $p = Patient::factory()->create(['hospital_id' => $h->id]);
        $original = $p->patient_no;
        $this->actingReceptionist($h);

        Livewire::test(Form::class, ['patient' => $p])
            ->set('first_name', 'Changed')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($original, $p->fresh()->patient_no);
    }

    public function test_a_role_without_create_permission_is_forbidden(): void
    {
        $h = Hospital::factory()->create();
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'receptionist']);
        $u->syncRoles([]);
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        Livewire::test(Form::class)->assertForbidden();
    }
}
