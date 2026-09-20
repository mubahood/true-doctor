<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Patients\Show;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The patient record workspace (Patients\Show) — the page that replaced
 * admin/patients/show.blade.php and PatientController@show/update/destroy.
 */
class PatientShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function staff(Hospital $h, string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function acting(Hospital $h, string $role): User
    {
        $u = $this->staff($h, $role);
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    public function test_it_renders_the_record_for_a_permitted_role(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'receptionist');
        $patient = Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Grace', 'last_name' => 'Nakato']);

        Livewire::test(Show::class, ['patient' => $patient])
            ->assertOk()
            ->assertSee('Grace Nakato')
            ->assertSee($patient->patient_no)
            ->assertSee('Consent');
    }

    public function test_the_full_page_route_renders_with_a_server_side_title(): void
    {
        $h = Hospital::factory()->create();
        $user = $this->staff($h, 'receptionist');
        $patient = Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Grace', 'last_name' => 'Nakato']);

        $this->actingAs($user)
            ->get("/admin/patients/{$patient->uuid}")
            ->assertOk()
            ->assertSee('<title>Grace Nakato · True-Doctor</title>', false)
            ->assertSee($patient->patient_no);
    }

    public function test_a_role_without_patient_view_is_forbidden(): void
    {
        $h = Hospital::factory()->create();
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'receptionist']);
        $u->syncRoles([]);
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        Livewire::test(Show::class, ['patient' => $patient])->assertForbidden();
    }

    public function test_a_patient_of_another_hospital_is_not_found(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $patientB = Patient::factory()->create(['hospital_id' => $b->id, 'first_name' => 'Secret']);

        $this->actingAs($this->staff($a, 'hospital_admin'))
            ->get("/admin/patients/{$patientB->uuid}")
            ->assertNotFound();

        $this->assertSame('Secret', Patient::withoutGlobalScopes()->find($patientB->id)->first_name);
    }

    public function test_archive_soft_deletes_the_patient_and_redirects_to_the_index(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'records_officer');
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        Livewire::test(Show::class, ['patient' => $patient])
            ->call('archive')
            ->assertRedirect(route('admin.patients.index'));

        $this->assertSoftDeleted('patients', ['id' => $patient->id]);
        $this->assertSame("Patient {$patient->patient_no} archived.", session('success'));
    }

    public function test_a_role_without_delete_permission_cannot_archive(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'receptionist'); // patients.view/create/update, no patients.delete
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        Livewire::test(Show::class, ['patient' => $patient])
            ->call('archive')
            ->assertForbidden();

        $this->assertNotSoftDeleted('patients', ['id' => $patient->id]);
    }

    public function test_the_cards_panel_is_hidden_from_a_role_that_cannot_manage_cards(): void
    {
        $h = Hospital::factory()->create();
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        // Doctor: patients.view but no patients.card.manage (plan D5).
        $this->acting($h, 'doctor');
        Livewire::withoutLazyLoading();
        Livewire::test(Show::class, ['patient' => $patient])
            ->assertOk()
            ->assertDontSee('Prepaid cards');

        // Receptionist: patients.card.manage → the panel renders.
        $this->acting($h, 'receptionist');
        Livewire::withoutLazyLoading();
        Livewire::test(Show::class, ['patient' => $patient])
            ->assertOk()
            ->assertSee('Prepaid cards');
    }
}
