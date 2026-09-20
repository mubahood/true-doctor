<?php

namespace Tests\Feature\Livewire;

use App\Enums\AppointmentStatus;
use App\Enums\Weekday;
use App\Livewire\Appointments\Index as Appointments;
use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Room;
use App\Models\User;
use App\Services\AppointmentService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The diary: read as a list or as a week, and changed from the row it is on.
 *
 * Moving an appointment was a page away behind a detail screen — and moving
 * one is the commonest thing anybody does to an appointment after making it.
 * The row now carries the next legal step and a menu for the rest, and the
 * change dialog is the BOOKING dialog, so a reschedule gets the same day strip
 * and the same list of times the doctor is really free.
 */
class AppointmentDiaryTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $doctor;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        // A Wednesday.
        Carbon::setTestNow(Carbon::parse('2026-09-16 09:10:00'));

        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $admin = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin']);
        $admin->syncSpatieRole();
        $this->actingAs($admin);

        $this->doctor = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor', 'name' => 'Dr Kasujja']);
        $this->patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);

        foreach ([Weekday::Wednesday, Weekday::Thursday, Weekday::Friday] as $day) {
            DoctorSchedule::create([
                'hospital_id' => $this->hospital->id, 'user_id' => $this->doctor->id,
                'weekday' => $day->value, 'start_time' => '08:00', 'end_time' => '17:00',
                'slot_minutes' => 30, 'is_active' => true,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function booked(string $at = '2026-09-17 10:00', int $minutes = 30): Appointment
    {
        return app(AppointmentService::class)->book([
            'patient_id' => $this->patient->id,
            'doctor_user_id' => $this->doctor->id,
            'scheduled_at' => $at,
            'duration_minutes' => $minutes,
            'source' => 'walk_in',
        ], auth()->id());
    }

    private function page(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Appointments::class);
    }

    // ── Reading it as a week ─────────────────────────────────────────────

    public function test_the_week_runs_monday_to_sunday_around_the_chosen_date(): void
    {
        $week = $this->page()->set('date', '2026-09-17')->instance()->week();

        $this->assertCount(7, $week);
        $this->assertSame('2026-09-14', $week[0]['date']->format('Y-m-d'), 'Monday');
        $this->assertSame('2026-09-20', $week[6]['date']->format('Y-m-d'), 'Sunday');
    }

    public function test_with_no_date_the_calendar_opens_on_this_week(): void
    {
        $this->assertSame('2026-09-14', $this->page()->instance()->weekStart()->format('Y-m-d'));
    }

    public function test_an_appointment_lands_on_its_own_day(): void
    {
        $this->booked('2026-09-17 10:00');

        $week = $this->page()->set('view', 'calendar')->instance()->week();

        $this->assertCount(0, $week[2]['appointments'], 'Wednesday');
        $this->assertCount(1, $week[3]['appointments'], 'Thursday');
    }

    public function test_the_week_can_be_stepped_and_brought_back(): void
    {
        $page = $this->page()->set('view', 'calendar');

        $page->call('shiftWeek', 1);
        $this->assertSame('2026-09-21', $page->instance()->weekStart()->format('Y-m-d'));

        $page->call('shiftWeek', -2);
        $this->assertSame('2026-09-07', $page->instance()->weekStart()->format('Y-m-d'));

        $page->call('thisWeek');
        $this->assertSame('2026-09-14', $page->instance()->weekStart()->format('Y-m-d'));
    }

    /** One set of filters; two shapes of the same answer. */
    public function test_the_calendar_obeys_the_doctor_filter(): void
    {
        $this->booked('2026-09-17 10:00');

        $other = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor']);

        $page = $this->page()->set('view', 'calendar')->set('doctor', (string) $other->id);

        $this->assertCount(0, $page->instance()->week()[3]['appointments']);
    }

    public function test_the_calendar_obeys_the_status_filter(): void
    {
        $appointment = $this->booked('2026-09-17 10:00');
        $appointment->update(['status' => AppointmentStatus::Cancelled]);

        $page = $this->page()->set('view', 'calendar');

        $this->assertCount(1, $page->instance()->week()[3]['appointments']);
        $this->assertCount(0, $page->set('status', 'scheduled')->instance()->week()[3]['appointments']);
    }

    public function test_the_week_never_reaches_another_hospital(): void
    {
        $this->booked('2026-09-17 10:00');

        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $theirDoctor = User::factory()->create(['hospital_id' => $other->id, 'role' => 'doctor']);
        $theirPatient = Patient::factory()->create(['hospital_id' => $other->id]);
        DoctorSchedule::create([
            'hospital_id' => $other->id, 'user_id' => $theirDoctor->id,
            'weekday' => Weekday::Thursday->value, 'start_time' => '08:00', 'end_time' => '17:00',
            'slot_minutes' => 30, 'is_active' => true,
        ]);
        app(AppointmentService::class)->book([
            'patient_id' => $theirPatient->id, 'doctor_user_id' => $theirDoctor->id,
            'scheduled_at' => '2026-09-17 11:00', 'duration_minutes' => 30, 'source' => 'walk_in',
        ], null);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->assertCount(1, $this->page()->set('view', 'calendar')->instance()->week()[3]['appointments']);
    }

    public function test_a_nonsense_view_falls_back_to_the_list(): void
    {
        $this->page()->set('view', 'wallchart')->assertSet('view', 'list');
    }

    // ── Changing one from its row ────────────────────────────────────────

    public function test_editing_fills_the_dialog_from_the_appointment(): void
    {
        $room = Room::factory()->create(['hospital_id' => $this->hospital->id]);
        $appointment = $this->booked('2026-09-17 10:00', 45);
        $appointment->update(['room_id' => $room->id, 'reason' => 'Review of results']);

        $this->page()->call('edit', $appointment->id)
            ->assertSet('showForm', true)
            ->assertSet('editingId', $appointment->id)
            ->assertSet('patient_id', $this->patient->id)
            ->assertSet('doctor_user_id', $this->doctor->id)
            ->assertSet('room_id', $room->id)
            ->assertSet('duration_minutes', 45)
            ->assertSet('scheduled_at', '2026-09-17T10:00')
            ->assertSet('reason', 'Review of results');
    }

    /** The reschedule gets the free-time picker, because it is the same dialog. */
    public function test_editing_offers_the_doctors_free_times(): void
    {
        $appointment = $this->booked('2026-09-17 10:00');

        $slots = $this->page()->call('edit', $appointment->id)->instance()->openSlots();

        $this->assertContains('08:00', $slots);
        $this->assertContains('10:00', $slots, 'its own time must stay on offer');
    }

    public function test_saving_moves_the_appointment(): void
    {
        $appointment = $this->booked('2026-09-17 10:00');

        $this->page()->call('edit', $appointment->id)
            ->call('chooseTime', '14:00')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false)
            ->assertSet('editingId', null);

        $this->assertSame('2026-09-17 14:00:00', $appointment->fresh()->scheduled_at->format('Y-m-d H:i:s'));
    }

    public function test_saving_keeps_what_the_appointment_is_for(): void
    {
        $appointment = $this->booked('2026-09-17 10:00');

        $this->page()->call('edit', $appointment->id)
            ->set('reason', 'Blood pressure review')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Blood pressure review', $appointment->fresh()->reason);
    }

    /** Moving one onto a time somebody else holds must be refused, not saved. */
    public function test_a_clash_is_refused_with_the_reason_on_the_field(): void
    {
        $mine = $this->booked('2026-09-17 10:00');
        $this->booked('2026-09-17 11:00');

        $this->page()->call('edit', $mine->id)
            ->set('scheduled_at', '2026-09-17T11:00')
            ->call('save')
            ->assertHasErrors('scheduled_at');

        $this->assertSame('10:00', $mine->fresh()->scheduled_at->format('H:i'), 'it must not have moved');
    }

    public function test_a_finished_appointment_cannot_be_moved(): void
    {
        $appointment = $this->booked('2026-09-17 10:00');
        $appointment->update(['status' => AppointmentStatus::Completed]);

        $this->page()->call('edit', $appointment->id)->assertSet('showForm', false);
    }

    public function test_booking_after_editing_starts_from_a_clean_form(): void
    {
        $appointment = $this->booked('2026-09-17 10:00');

        $this->page()->call('edit', $appointment->id)
            ->call('create')
            ->assertSet('editingId', null)
            ->assertSet('patient_id', null)
            ->assertSet('scheduled_at', null);
    }

    // ── Moving it along ──────────────────────────────────────────────────

    public function test_the_row_advances_the_status(): void
    {
        $appointment = $this->booked('2026-09-17 10:00');

        $this->page()->call('advance', $appointment->id, 'confirmed');

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);
    }

    public function test_an_illegal_jump_is_refused(): void
    {
        $appointment = $this->booked('2026-09-17 10:00');

        $this->page()->call('advance', $appointment->id, 'completed');

        $this->assertSame(AppointmentStatus::Scheduled, $appointment->fresh()->status);
    }

    public function test_an_unknown_status_changes_nothing(): void
    {
        $appointment = $this->booked('2026-09-17 10:00');

        $this->page()->call('advance', $appointment->id, 'teleported');

        $this->assertSame(AppointmentStatus::Scheduled, $appointment->fresh()->status);
    }

    // ── Ending one asks why ──────────────────────────────────────────────

    /**
     * A cancellation is not a status change with a shrug: the next person to
     * read the record needs to know whether they rang or simply did not come.
     */
    public function test_cancelling_opens_the_reason_dialog_rather_than_cancelling(): void
    {
        $appointment = $this->booked('2026-09-17 10:00');

        $this->page()->call('advance', $appointment->id, 'cancelled')
            ->assertSet('showEnding', true)
            ->assertSet('endingId', $appointment->id)
            ->assertSet('endingTo', 'cancelled');

        $this->assertSame(AppointmentStatus::Scheduled, $appointment->fresh()->status, 'not yet');
    }

    public function test_confirming_the_reason_cancels_and_records_it(): void
    {
        $appointment = $this->booked('2026-09-17 10:00');

        $this->page()->call('advance', $appointment->id, 'cancelled')
            ->set('note', 'Patient rang to cancel')
            ->call('endAppointment')
            ->assertSet('showEnding', false)
            ->assertSet('endingId', null);

        $fresh = $appointment->fresh();
        $this->assertSame(AppointmentStatus::Cancelled, $fresh->status);
        $this->assertSame('Patient rang to cancel', $fresh->cancel_reason);
    }

    public function test_a_no_show_goes_through_the_same_dialog(): void
    {
        $appointment = $this->booked('2026-09-17 10:00');

        $this->page()->call('advance', $appointment->id, 'no_show')
            ->assertSet('endingTo', 'no_show')
            ->call('endAppointment');

        $this->assertSame(AppointmentStatus::NoShow, $appointment->fresh()->status);
    }

    public function test_backing_out_leaves_the_appointment_alone(): void
    {
        $appointment = $this->booked('2026-09-17 10:00');

        $this->page()->call('advance', $appointment->id, 'cancelled')
            ->call('cancelEnding')
            ->assertSet('showEnding', false);

        $this->assertSame(AppointmentStatus::Scheduled, $appointment->fresh()->status);
    }

    public function test_the_reason_is_optional(): void
    {
        $appointment = $this->booked('2026-09-17 10:00');

        $this->page()->call('advance', $appointment->id, 'cancelled')
            ->call('endAppointment')
            ->assertHasNoErrors();

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
    }

    public function test_confirming_nothing_does_nothing(): void
    {
        $this->page()->call('endAppointment')->assertSet('showEnding', false);

        $this->assertSame(0, Appointment::count());
    }

    // ── Permission ───────────────────────────────────────────────────────

    public function test_a_nurse_can_read_the_diary_but_not_change_one(): void
    {
        $appointment = $this->booked('2026-09-17 10:00');

        $nurse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $nurse->syncSpatieRole();
        $this->actingAs($nurse);

        Livewire::test(Appointments::class)->assertOk();
        Livewire::test(Appointments::class)->call('advance', $appointment->id, 'confirmed')->assertForbidden();

        $this->assertSame(AppointmentStatus::Scheduled, $appointment->fresh()->status);
    }

    public function test_another_hospitals_appointment_cannot_be_edited(): void
    {
        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $theirDoctor = User::factory()->create(['hospital_id' => $other->id, 'role' => 'doctor']);
        $theirPatient = Patient::factory()->create(['hospital_id' => $other->id]);
        DoctorSchedule::create([
            'hospital_id' => $other->id, 'user_id' => $theirDoctor->id,
            'weekday' => Weekday::Thursday->value, 'start_time' => '08:00', 'end_time' => '17:00',
            'slot_minutes' => 30, 'is_active' => true,
        ]);
        $theirs = app(AppointmentService::class)->book([
            'patient_id' => $theirPatient->id, 'doctor_user_id' => $theirDoctor->id,
            'scheduled_at' => '2026-09-17 11:00', 'duration_minutes' => 30, 'source' => 'walk_in',
        ], null);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->page()->call('edit', $theirs->id);
    }

    // ── Reading one without leaving the list ─────────────────────────────

    /**
     * Opening a whole page to answer "what is this one, again?" loses the
     * place somebody is working down a list — and the answer is six lines.
     */
    public function test_the_quick_view_shows_the_whole_record(): void
    {
        $room = Room::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Consultation Room 1']);
        $appointment = $this->booked('2026-09-17 10:00');
        $appointment->update(['room_id' => $room->id, 'reason' => 'Review of results']);

        $this->page()->call('peek', $appointment->id)
            ->assertSet('showPeek', true)
            ->assertSee($this->patient->full_name)
            ->assertSee($this->patient->patient_no)
            ->assertSee('Dr Kasujja')
            ->assertSee('Consultation Room 1')
            ->assertSee('Review of results')
            ->assertSee('10:00–10:30');
    }

    /** What has happened to it is the part a list cannot show at all. */
    public function test_the_quick_view_carries_the_trail(): void
    {
        $appointment = $this->booked('2026-09-17 10:00');
        $this->page()->call('advance', $appointment->id, 'confirmed');

        $this->page()->call('peek', $appointment->id)
            ->assertSee('Trail')
            ->assertSee('Confirmed');
    }

    public function test_a_cancelled_appointment_shows_why_it_ended(): void
    {
        $appointment = $this->booked('2026-09-17 10:00');
        $this->page()->call('advance', $appointment->id, 'cancelled')
            ->set('note', 'Patient rang to cancel')
            ->call('endAppointment');

        $this->page()->call('peek', $appointment->id)->assertSee('Patient rang to cancel');
    }

    public function test_closing_the_quick_view_forgets_it(): void
    {
        $appointment = $this->booked('2026-09-17 10:00');

        $this->page()->call('peek', $appointment->id)
            ->call('closePeek')
            ->assertSet('showPeek', false)
            ->assertSet('peekId', null);
    }

    /** Two dialogs stacked over each other is a place to get lost in. */
    public function test_changing_from_the_quick_view_replaces_it(): void
    {
        $appointment = $this->booked('2026-09-17 10:00');

        $this->page()->call('peek', $appointment->id)
            ->call('edit', $appointment->id)
            ->assertSet('showPeek', false)
            ->assertSet('showForm', true);
    }

    public function test_advancing_from_the_quick_view_closes_it(): void
    {
        $appointment = $this->booked('2026-09-17 10:00');

        $this->page()->call('peek', $appointment->id)
            ->call('advance', $appointment->id, 'confirmed')
            ->assertSet('showPeek', false);

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);
    }

    /** Reading is not changing: a nurse may look at one. */
    public function test_a_nurse_may_read_one_in_the_quick_view(): void
    {
        $appointment = $this->booked('2026-09-17 10:00');

        $nurse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $nurse->syncSpatieRole();
        $this->actingAs($nurse);

        Livewire::test(Appointments::class)->call('peek', $appointment->id)->assertSet('showPeek', true);
    }

    public function test_another_hospitals_appointment_cannot_be_read(): void
    {
        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $theirDoctor = User::factory()->create(['hospital_id' => $other->id, 'role' => 'doctor']);
        $theirPatient = Patient::factory()->create(['hospital_id' => $other->id]);
        DoctorSchedule::create([
            'hospital_id' => $other->id, 'user_id' => $theirDoctor->id,
            'weekday' => Weekday::Thursday->value, 'start_time' => '08:00', 'end_time' => '17:00',
            'slot_minutes' => 30, 'is_active' => true,
        ]);
        $theirs = app(AppointmentService::class)->book([
            'patient_id' => $theirPatient->id, 'doctor_user_id' => $theirDoctor->id,
            'scheduled_at' => '2026-09-17 11:00', 'duration_minutes' => 30, 'source' => 'walk_in',
        ], null);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->page()->call('peek', $theirs->id);
    }

    /** The full page is still there, one click further on. */
    public function test_the_quick_view_links_to_the_full_record(): void
    {
        $appointment = $this->booked('2026-09-17 10:00');

        $this->page()->call('peek', $appointment->id)
            ->assertSee(route('admin.appointments.show', $appointment), false)
            ->assertSee('Full record');
    }

    // ── The table itself ─────────────────────────────────────────────────

    /** With no date filter the list spans the diary, so the hour is not enough. */
    public function test_the_list_shows_which_day_each_row_is(): void
    {
        $this->booked('2026-09-17 10:00');

        $this->page()->assertSee('Tomorrow')->assertSee('10:00–10:30');
    }

    public function test_a_hand_typed_sort_column_is_refused(): void
    {
        $this->page()->call('sortBy', 'hospital_id')->assertSet('sortField', '');
        $this->page()->call('sortBy', 'scheduled_at')->assertSet('sortField', 'scheduled_at');
    }

    public function test_clearing_puts_every_filter_back(): void
    {
        $this->page()
            ->set('search', 'vinson')->set('status', 'scheduled')->set('date', '2026-09-17')
            ->call('clearFilters')
            ->assertSet('search', '')->assertSet('status', '')->assertSet('date', '');
    }
}
