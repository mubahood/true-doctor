<?php

namespace Tests\Feature;

use App\Livewire\Patients\Form;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Registration through the only surviving surface — the Livewire patient
 * editor (PatientController@store was retired with the classic forms). Same
 * assertions as the HTTP suite this replaced: tenant, number sequence,
 * registered_by, consent stamp, RBAC and validation.
 */
class PatientRegistrationTest extends TestCase
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

    public function test_receptionist_registers_a_patient_scoped_to_their_hospital(): void
    {
        $h = Hospital::factory()->create();
        $receptionist = $this->acting($h, 'receptionist');

        Livewire::test(Form::class)
            ->set('first_name', 'Grace')
            ->set('last_name', 'Achieng')
            ->set('sex', 'female')
            ->set('phone_1', '+256700000001')
            ->set('consent_given', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $p = Patient::withoutGlobalScopes()->where('first_name', 'Grace')->firstOrFail();
        $this->assertSame($h->id, $p->hospital_id);
        $this->assertStringStartsWith('PT-'.date('Y').'-', $p->patient_no);
        $this->assertSame($receptionist->id, $p->registered_by);
        $this->assertNotNull($p->consent_at);
    }

    public function test_patient_numbers_increment_per_hospital(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'receptionist');

        foreach ([['A', 'One'], ['B', 'Two']] as [$first, $last]) {
            Livewire::test(Form::class)
                ->set('first_name', $first)
                ->set('last_name', $last)
                ->set('consent_given', true)
                ->call('save')
                ->assertHasNoErrors();
        }

        $nos = Patient::withoutGlobalScopes()->orderBy('id')->pluck('patient_no')->all();
        $this->assertStringStartsWith('PT-'.date('Y').'-000001', $nos[0]);
        $this->assertStringStartsWith('PT-'.date('Y').'-000002', $nos[1]);
    }

    public function test_a_role_without_create_permission_cannot_register(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'doctor'); // has patients.view/update, not create

        Livewire::test(Form::class)->assertForbidden();

        $this->assertSame(0, Patient::withoutGlobalScopes()->count());
    }

    public function test_validation_rejects_a_patient_with_no_name(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'receptionist');

        Livewire::test(Form::class)
            ->set('first_name', '')
            ->set('last_name', '')
            ->call('save')
            ->assertHasErrors(['first_name', 'last_name']);

        $this->assertSame(0, Patient::withoutGlobalScopes()->count());
    }
}
