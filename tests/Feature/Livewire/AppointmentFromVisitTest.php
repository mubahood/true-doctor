<?php

namespace Tests\Feature\Livewire;

use App\Enums\AppointmentSource;
use App\Livewire\Appointments\Index as AppointmentsIndex;
use App\Models\Appointment;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Services\VisitService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * An appointment belongs to an attendance, not to a name out of the register.
 *
 * Two different links, and the test exists partly to keep them apart:
 *
 *   `visits.appointment_id`        the visit a booking BECAME  (forwards)
 *   `appointments.origin_visit_id` the visit a booking CAME FROM (backwards)
 *
 * The second is new. The commonest booking in a hospital by far is "come back
 * in two weeks", said in the consulting room — and until now it arrived at the
 * diary as a patient picked out of the whole register, with nothing tying it to
 * the attendance that prompted it.
 */
class AppointmentFromVisitTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $admin;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->admin = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin']);
        $this->admin->syncSpatieRole();

        $this->doctor = User::factory()->create([
            'hospital_id' => $this->hospital->id, 'role' => 'doctor', 'name' => 'Dr Aisha Namara',
        ]);
        $this->doctor->syncSpatieRole();

        $this->actingAs($this->admin);
    }

    private function patient(): Patient
    {
        return Patient::factory()->create(['hospital_id' => $this->hospital->id]);
    }

    private function visit(array $attributes = []): Visit
    {
        return app(VisitService::class)->open(array_merge([
            'patient_id' => $this->patient()->id,
            'doctor_user_id' => $this->doctor->id,
        ], $attributes));
    }

    private function dialog(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(AppointmentsIndex::class)->call('create');
    }

    /**
     * A slot the doctor is actually available for.
     *
     * AppointmentService refuses a booking outside a doctor's availability, so
     * a test that only supplies a time is testing the refusal.
     */
    private function bookableSlot(): Carbon
    {
        $slot = Carbon::tomorrow()->setTime(10, 0);

        \App\Models\DoctorSchedule::create([
            'hospital_id' => $this->hospital->id,
            'user_id' => $this->doctor->id,
            'weekday' => $slot->dayOfWeek,
            'start_time' => '08:00',
            'end_time' => '17:00',
            'slot_minutes' => 30,
            'is_active' => true,
        ]);

        return $slot;
    }

    // ── Booking out of a visit ───────────────────────────────────────────

    public function test_choosing_a_visit_brings_its_patient_doctor_and_department(): void
    {
        $department = Department::factory()->create(['hospital_id' => $this->hospital->id]);
        $visit = $this->visit(['department_id' => $department->id]);

        $this->dialog()
            ->call('picked', 'origin_visit_id', $visit->id)
            ->assertSet('patient_id', $visit->patient_id)
            ->assertSet('doctor_user_id', $this->doctor->id)
            ->assertSet('department_id', $department->id);
    }

    /** The patient is taken from the visit, not offered beside it. */
    public function test_the_patient_is_shown_rather_than_picked_once_a_visit_is_chosen(): void
    {
        $visit = $this->visit();

        $dialog = $this->dialog();
        $dialog->assertSeeHtml('resource="patients"');

        $dialog->call('picked', 'origin_visit_id', $visit->id)
            ->assertDontSeeHtml('resource="patients"')
            ->assertSee($visit->patient->full_name)
            ->assertSee($visit->visit_no);
    }

    public function test_the_booking_records_the_visit_it_came_out_of(): void
    {
        $visit = $this->visit();

        $this->dialog()
            ->call('picked', 'origin_visit_id', $visit->id)
            ->set('scheduled_at', $this->bookableSlot()->format('Y-m-d\TH:i'))
            ->set('duration_minutes', 30)
            ->set('source', AppointmentSource::cases()[0]->value)
            ->set('reason', 'Review after two weeks')
            ->call('save')
            ->assertHasNoErrors();

        $appointment = Appointment::firstOrFail();

        $this->assertSame($visit->id, $appointment->origin_visit_id);
        $this->assertSame($visit->patient_id, $appointment->patient_id);
        $this->assertTrue($appointment->isFollowUp());
        $this->assertSame($visit->id, $appointment->originVisit->id);
    }

    /**
     * The service takes the patient FROM the visit rather than trusting what
     * arrived beside it — otherwise a tampered request could book somebody
     * else's appointment against a visit.
     */
    public function test_the_patient_always_comes_from_the_visit(): void
    {
        $visit = $this->visit();
        $somebodyElse = $this->patient();

        $appointment = app(\App\Services\AppointmentService::class)->book([
            'patient_id' => $somebodyElse->id,
            'origin_visit_id' => $visit->id,
            'doctor_user_id' => $this->doctor->id,
            'scheduled_at' => $this->bookableSlot()->toDateTimeString(),
            'duration_minutes' => 30,
            'source' => AppointmentSource::cases()[0]->value,
        ], $this->admin->id);

        $this->assertSame($visit->patient_id, $appointment->patient_id);
        $this->assertNotSame($somebodyElse->id, $appointment->patient_id);
    }

    public function test_another_hospitals_visit_cannot_be_booked_against(): void
    {
        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $theirs = app(VisitService::class)->open([
            'patient_id' => Patient::factory()->create(['hospital_id' => $other->id])->id,
        ]);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->expectException(\RuntimeException::class);
        app(\App\Services\AppointmentService::class)->book([
            'patient_id' => $this->patient()->id,
            'origin_visit_id' => $theirs->id,
            'doctor_user_id' => $this->doctor->id,
            'scheduled_at' => Carbon::tomorrow()->setHour(9)->toDateTimeString(),
            'duration_minutes' => 30,
            'source' => AppointmentSource::cases()[0]->value,
        ], $this->admin->id);
    }

    /** A cold booking over the telephone still works, and has no origin. */
    public function test_a_booking_with_no_visit_is_still_allowed(): void
    {
        $patient = $this->patient();

        $this->dialog()
            ->call('picked', 'patient_id', $patient->id)
            ->call('picked', 'doctor_user_id', $this->doctor->id)
            ->set('scheduled_at', $this->bookableSlot()->format('Y-m-d\TH:i'))
            ->set('duration_minutes', 30)
            ->set('source', AppointmentSource::cases()[0]->value)
            ->call('save')
            ->assertHasNoErrors();

        $appointment = Appointment::firstOrFail();

        $this->assertNull($appointment->origin_visit_id);
        $this->assertFalse($appointment->isFollowUp());
        $this->assertSame($patient->id, $appointment->patient_id);
    }

    /** Dropping the visit releases the patient it was holding. */
    public function test_clearing_the_visit_releases_its_patient(): void
    {
        $visit = $this->visit();

        $this->dialog()
            ->call('picked', 'origin_visit_id', $visit->id)
            ->assertSet('patient_id', $visit->patient_id)
            ->call('cleared', 'origin_visit_id')
            ->assertSet('origin_visit_id', null)
            ->assertSet('patient_id', null);
    }

    // ── The diary ────────────────────────────────────────────────────────

    /**
     * The date filter starts EMPTY.
     *
     * Defaulting it to today made the page a diary for one day and hid
     * everything else behind a filter nobody had set — a booking made for next
     * week disappeared the moment it was saved.
     */
    public function test_the_diary_opens_on_every_appointment_not_only_today(): void
    {
        $patient = $this->patient();

        foreach ([Carbon::now()->addDays(6), Carbon::now()->subDays(3)] as $when) {
            Appointment::create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'hospital_id' => $this->hospital->id,
                'patient_id' => $patient->id,
                'doctor_user_id' => $this->doctor->id,
                'scheduled_at' => $when,
                'ends_at' => $when->copy()->addMinutes(30),
                'duration_minutes' => 30,
                'source' => AppointmentSource::cases()[0]->value,
                'status' => \App\Enums\AppointmentStatus::Scheduled,
            ]);
        }

        $page = Livewire::test(AppointmentsIndex::class);

        $page->assertSet('date', '');
        $this->assertSame(2, $page->viewData('rows')->total(), 'the diary still hid everything but today');
    }

    /** What is coming is what the page was opened for, so it leads. */
    public function test_the_whole_diary_puts_the_future_first(): void
    {
        $patient = $this->patient();

        $past = Carbon::now()->subDays(2);
        $future = Carbon::now()->addDays(2);

        foreach ([$past, $future] as $when) {
            Appointment::create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'hospital_id' => $this->hospital->id,
                'patient_id' => $patient->id,
                'doctor_user_id' => $this->doctor->id,
                'scheduled_at' => $when,
                'ends_at' => $when->copy()->addMinutes(30),
                'duration_minutes' => 30,
                'source' => AppointmentSource::cases()[0]->value,
                'status' => \App\Enums\AppointmentStatus::Scheduled,
            ]);
        }

        $rows = Livewire::test(AppointmentsIndex::class)->viewData('rows');

        $this->assertTrue(
            $rows->items()[0]->scheduled_at->isFuture(),
            'the diary opens on something that has already happened',
        );
    }

    /** Setting a date still narrows it to that day, in the order of the day. */
    public function test_a_date_still_narrows_the_diary_to_one_day(): void
    {
        $patient = $this->patient();
        $day = Carbon::now()->addDays(4);

        Appointment::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'hospital_id' => $this->hospital->id,
            'patient_id' => $patient->id,
            'doctor_user_id' => $this->doctor->id,
            'scheduled_at' => $day,
            'ends_at' => $day->copy()->addMinutes(30),
            'duration_minutes' => 30,
            'source' => AppointmentSource::cases()[0]->value,
            'status' => \App\Enums\AppointmentStatus::Scheduled,
        ]);

        $page = Livewire::test(AppointmentsIndex::class);
        $this->assertSame(1, $page->viewData('rows')->total());

        $page->set('date', Carbon::now()->addDays(9)->toDateString());
        $this->assertSame(0, $page->viewData('rows')->total(), 'the date filter no longer narrows anything');
    }

    // ── The picker ───────────────────────────────────────────────────────

    public function test_the_visit_picker_offers_open_visits_and_never_crosses_a_hospital(): void
    {
        $mine = $this->visit();

        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        app(VisitService::class)->open(['patient_id' => Patient::factory()->create(['hospital_id' => $other->id])->id]);
        app(CurrentHospital::class)->set($this->hospital->id);

        $picker = Livewire::test(\App\Livewire\Ui\SelectSearch::class, [
            'resource' => 'visits', 'name' => 'origin_visit_id',
        ])->call('openList');

        $browse = $picker->instance()->browse();
        $ids = array_column(array_merge($browse['likely'], $browse['rest']), 'id');

        $this->assertContains($mine->id, $ids);
        $this->assertCount(1, array_unique($ids));
    }
}
