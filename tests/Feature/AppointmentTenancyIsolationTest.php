<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\Weekday;
use App\Livewire\Appointments\Queue as AppointmentQueue;
use App\Livewire\Appointments\Show as AppointmentShow;
use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Services\AppointmentService;
use App\Support\CurrentHospital;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * §2.1 — scheduling ships an A-vs-B isolation test: hospital A can neither see
 * nor act on hospital B's appointments or availability, and booking can never
 * reference another hospital's doctor/patient.
 */
class AppointmentTenancyIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function admin(Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $u->syncSpatieRole();

        return $u;
    }

    private function bookFor(Hospital $h): Appointment
    {
        app(CurrentHospital::class)->set($h->id);
        $doctor = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor']);
        DoctorSchedule::factory()->create([
            'hospital_id' => $h->id, 'user_id' => $doctor->id,
            'weekday' => Weekday::Monday, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'slot_minutes' => 30,
        ]);
        $patient = Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Zeb', 'last_name' => 'FromB']);

        return app(AppointmentService::class)->book([
            'patient_id' => $patient->id, 'doctor_user_id' => $doctor->id,
            'scheduled_at' => '2026-08-03 09:00', 'duration_minutes' => 30,
        ]);
    }

    public function test_hospital_a_cannot_see_or_act_on_bs_appointment(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $apptB = $this->bookFor($b);
        $adminA = $this->admin($a);

        $this->actingAs($adminA)->get('/admin/appointments?date=2026-08-03')->assertOk()->assertDontSee('FromB');
        $this->actingAs($adminA)->get("/admin/appointments/{$apptB->uuid}")->assertNotFound();

        // The detail page and the queue both resolve through the tenant scope:
        // B's appointment is simply not there for A, in mount or in an action.
        app(CurrentHospital::class)->set($a->id);

        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($adminA)->test(AppointmentShow::class, ['appointment' => $apptB->uuid]);
    }

    public function test_hospital_a_cannot_transition_bs_appointment_from_the_queue(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $apptB = $this->bookFor($b);
        $adminA = $this->admin($a);

        app(CurrentHospital::class)->set($a->id);

        try {
            Livewire::actingAs($adminA)->test(AppointmentQueue::class)
                ->call('advance', $apptB->id, 'cancelled');
            $this->fail('A cross-tenant appointment id must not be reachable.');
        } catch (ModelNotFoundException) {
            // expected
        }

        $this->assertSame(AppointmentStatus::Scheduled, $apptB->fresh()->status);
    }

    public function test_cannot_book_against_another_hospitals_doctor(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $doctorB = User::factory()->create(['hospital_id' => $b->id, 'role' => 'doctor']);
        $patientA = Patient::factory()->create(['hospital_id' => $a->id]);

        $this->actingAs($this->admin($a));
        app(CurrentHospital::class)->set($a->id);

        Livewire::test(\App\Livewire\Appointments\Index::class)
            ->call('create')
            ->set('patient_id', $patientA->id)
            ->set('doctor_user_id', $doctorB->id)
            ->set('scheduled_at', now()->next(\Illuminate\Support\Carbon::MONDAY)->setTime(9, 0)->format('Y-m-d H:i'))
            ->set('duration_minutes', 30)
            ->set('source', 'walk_in')
            ->call('save')
            ->assertHasErrors('doctor_user_id');

        $this->assertDatabaseCount('appointments', 0);
    }
}
