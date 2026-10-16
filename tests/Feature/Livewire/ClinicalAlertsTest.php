<?php

namespace Tests\Feature\Livewire;

use App\Enums\PatientStatus;
use App\Livewire\Visits\Show;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Support\Clinical\PatientAlerts;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Allergies, long-term conditions and a deceased status are in front of every clinical screen. */
class ClinicalAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        Livewire::withoutLazyLoading();
    }

    private function visitFor(array $patient): Visit
    {
        $h = Hospital::factory()->create();
        $doctor = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor']);
        $doctor->syncSpatieRole();
        $this->actingAs($doctor);
        app(CurrentHospital::class)->set($h->id);
        $p = Patient::factory()->create(['hospital_id' => $h->id] + $patient);

        return Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $p->id]);
    }

    public function test_none_and_nkda_are_a_record_of_no_allergies_not_an_allergy(): void
    {
        $patient = new Patient(['allergies' => ['Penicillin', ' ', 'NKDA'], 'chronic_conditions' => ['Hypertension']]);

        $alerts = PatientAlerts::for($patient);

        $this->assertSame(['Penicillin'], $alerts['allergies']);
        $this->assertSame(['Hypertension'], $alerts['conditions']);
        $this->assertTrue($alerts['allergies_recorded']);
        $this->assertFalse(PatientAlerts::for(new Patient(['allergies' => null]))['allergies_recorded']);
    }

    public function test_a_visit_shows_the_allergies_and_conditions_at_the_top(): void
    {
        $visit = $this->visitFor(['allergies' => ['Penicillin', 'Sulfa'], 'chronic_conditions' => ['Diabetes']]);

        Livewire::test(Show::class, ['visit' => $visit])
            ->assertSeeInOrder(['Allergies', 'Penicillin', 'Sulfa', 'Long-term conditions', 'Diabetes']);
    }

    public function test_a_patient_nobody_asked_about_says_so_instead_of_showing_nothing(): void
    {
        $visit = $this->visitFor(['allergies' => null]);

        Livewire::test(Show::class, ['visit' => $visit])->assertSee('Allergies not recorded');
    }

    public function test_a_patient_with_none_recorded_says_no_known_allergies(): void
    {
        $visit = $this->visitFor(['allergies' => ['None']]);

        Livewire::test(Show::class, ['visit' => $visit])->assertSee('No known allergies')->assertDontSee('Allergies not recorded');
    }

    public function test_a_deceased_patient_is_flagged(): void
    {
        $visit = $this->visitFor(['allergies' => [], 'status' => PatientStatus::Deceased]);

        Livewire::test(Show::class, ['visit' => $visit])->assertSee('Deceased.');
    }

    public function test_every_clinical_page_carries_the_banner(): void
    {
        foreach (['visits/show', 'admissions/show', 'lab-orders/show', 'radiology-orders/show', 'dispensations/show'] as $view) {
            $this->assertStringContainsString('<x-clinical.alerts', (string) file_get_contents(resource_path("views/livewire/{$view}.blade.php")), "{$view} has no clinical alert banner");
        }
    }

    public function test_the_api_carries_the_alerts_wherever_a_patient_is_named(): void
    {
        $visit = $this->visitFor(['allergies' => ['Penicillin', 'NKDA'], 'chronic_conditions' => ['Asthma']]);
        \Laravel\Sanctum\Sanctum::actingAs(auth()->user());

        $this->getJson("/api/v1/visits/{$visit->uuid}")->assertOk()
            ->assertJsonPath('data.patient.alerts.allergies', ['Penicillin'])
            ->assertJsonPath('data.patient.alerts.conditions', ['Asthma'])
            ->assertJsonPath('data.patient.alerts.allergies_recorded', true);
    }
}
