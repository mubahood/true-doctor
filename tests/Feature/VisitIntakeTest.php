<?php

namespace Tests\Feature;

use App\Livewire\Visits\Index as VisitsIndex;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Inline intake (register a patient + open the visit in one transaction) is
 * now the "New patient" tab of the Livewire slide-over; the classic
 * POST /admin/visits/intake page is gone. Same assertions.
 */
class VisitIntakeTest extends TestCase
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

    public function test_inline_intake_registers_patient_and_opens_visit(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        Livewire::test(VisitsIndex::class)
            ->call('create')
            ->call('setTab', 'intake')
            ->assertSet('tab', 'intake')
            ->set('first_name', 'Grace')
            ->set('last_name', 'Newcomer')
            ->set('sex', 'female')
            ->set('phone_1', '0700000000')
            ->set('consent_given', true)
            ->set('reason', 'Headache')
            ->call('save')
            ->assertHasNoErrors()
            // The dialog closes and the list stays where it was: after
            // registering somebody the commonest next thing is registering the
            // next person, not reading the record just created.
            ->assertNoRedirect()
            ->assertSet('showForm', false)
            ->assertDispatched('toast');

        $patient = Patient::where('first_name', 'Grace')->firstOrFail();
        $this->assertSame($h->id, $patient->hospital_id);
        $this->assertNotNull($patient->patient_no);

        $visit = Visit::firstOrFail();
        $this->assertSame($patient->id, $visit->patient_id);
        $this->assertSame('Headache', $visit->reason);
    }

    public function test_intake_is_atomic_on_invalid_input(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        // Missing last_name → validation fails, nothing created.
        Livewire::test(VisitsIndex::class)
            ->call('create')
            ->call('setTab', 'intake')
            ->set('first_name', 'Half')
            ->set('reason', 'X')
            ->call('save')
            ->assertHasErrors('last_name');

        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('visits', 0);
    }

    /**
     * The dialog asks for a diagnosis "if it is already known" — and for a
     * while it was thrown away on the way to the database.
     */
    public function test_a_diagnosis_given_at_the_desk_is_kept(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        Livewire::test(VisitsIndex::class)
            ->call('create')
            ->set('patient_id', $patient->id)
            ->set('reason', 'Fever')
            ->set('diagnosis', 'Malaria, uncomplicated')
            ->set('pulse', '96')
            ->set('start_now', true)
            ->call('save')
            ->assertHasNoErrors();

        $visit = Visit::firstOrFail();
        $this->assertSame('Malaria, uncomplicated', $visit->diagnosis);
        $this->assertSame(96, (int) $visit->pulse);
        $this->assertSame('ongoing', $visit->status->value);

        // And the new-patient tab keeps it too.
        Livewire::test(VisitsIndex::class)
            ->call('create')
            ->call('setTab', 'intake')
            ->set('first_name', 'Walk')
            ->set('last_name', 'In')
            ->set('diagnosis', 'Sprained ankle')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Sprained ankle', Visit::latest('id')->firstOrFail()->diagnosis);
    }
}
