<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Patients\Panels\Insurances;
use App\Models\Hospital;
use App\Models\InsuranceProvider;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The insurance-coverage panel (ported from the retired
 * PatientInsuranceController routes): who may see it, who may write it, and
 * that a provider from another hospital can never be attached.
 */
class PatientInsurancesPanelTest extends TestCase
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

        return Livewire::test(Insurances::class, ['patientId' => $patient->id]);
    }

    public function test_an_accountant_adds_and_removes_coverage(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'accountant');
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $provider = InsuranceProvider::factory()->create(['hospital_id' => $h->id, 'name' => 'Jubilee', 'is_active' => true]);

        $this->panel($patient)
            ->call('openAdd')
            ->set('insurance_provider_id', $provider->id)
            ->set('member_no', 'MEM-001')
            ->set('coverage_percent', '80')
            ->call('add')
            ->assertHasNoErrors()
            ->assertSet('showAdd', false);

        $coverage = PatientInsurance::where('patient_id', $patient->id)->firstOrFail();
        $this->assertSame($provider->id, $coverage->insurance_provider_id);
        $this->assertSame('MEM-001', $coverage->member_no);
        $this->assertSame('80.00', (string) $coverage->coverage_percent);

        $this->panel($patient)->assertSee('Jubilee')->call('remove', $coverage->id)->assertHasNoErrors();
        $this->assertSoftDeleted('patient_insurances', ['id' => $coverage->id]);
    }

    public function test_validation_rejects_a_missing_provider_and_member_number(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'accountant');
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        $this->panel($patient)
            ->call('openAdd')
            ->call('add')
            ->assertHasErrors(['insurance_provider_id', 'member_no']);

        $this->assertDatabaseCount('patient_insurances', 0);
    }

    public function test_a_provider_of_another_hospital_cannot_be_attached(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $patientA = Patient::factory()->create(['hospital_id' => $a->id]);
        $providerB = InsuranceProvider::factory()->create(['hospital_id' => $b->id]);

        $this->acting($a, 'accountant');
        $this->panel($patientA)
            ->call('openAdd')
            ->set('insurance_provider_id', $providerB->id)
            ->set('member_no', 'MEM-002')
            ->call('add')
            ->assertHasErrors('insurance_provider_id');

        $this->assertDatabaseCount('patient_insurances', 0);
    }

    public function test_a_receptionist_can_view_but_not_write_coverage(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'receptionist'); // insurance.view, no insurance.manage
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        $this->panel($patient)->assertOk()->call('openAdd')->assertForbidden();
        $this->panel($patient)->call('add')->assertForbidden();
    }

    public function test_a_doctor_has_no_insurance_access(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'doctor');
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        $this->panel($patient)->assertForbidden();
    }

    public function test_a_patient_of_another_hospital_cannot_be_reached(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $patientB = Patient::factory()->create(['hospital_id' => $b->id]);

        $this->acting($a, 'accountant');
        $this->expectException(ModelNotFoundException::class);
        $this->panel($patientB);
    }
}
