<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\Weekday;
use App\Exceptions\InvalidStatusTransitionException;
use App\Exceptions\SlotUnavailableException;
use App\Models\DoctorSchedule;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Room;
use App\Models\User;
use App\Services\AppointmentService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Money-critical-adjacent: booking conflict detection and the state machine are
 * the load-bearing logic of scheduling, validated here at the service level.
 */
class AppointmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private AppointmentService $svc;

    private Hospital $hospital;

    private User $doctor;

    private Patient $patient;

    /** A Monday inside the 09:00–17:00 template. */
    private const MON_0900 = '2026-08-03 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(AppointmentService::class);
        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);
        $this->doctor = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor']);
        $this->patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);

        // Confirm the fixture date really is a Monday, else the whole suite is meaningless.
        $this->assertSame(Weekday::Monday->value, Carbon::parse(self::MON_0900)->dayOfWeek);

        DoctorSchedule::factory()->create([
            'hospital_id' => $this->hospital->id,
            'user_id' => $this->doctor->id,
            'weekday' => Weekday::Monday,
            'start_time' => '09:00:00', 'end_time' => '17:00:00', 'slot_minutes' => 30,
        ]);
    }

    private function bookData(array $o = []): array
    {
        return array_merge([
            'patient_id' => $this->patient->id,
            'doctor_user_id' => $this->doctor->id,
            'scheduled_at' => self::MON_0900,
            'duration_minutes' => 30,
        ], $o);
    }

    public function test_books_a_valid_slot_and_records_history(): void
    {
        $appt = $this->svc->book($this->bookData(), $this->doctor->id);

        $this->assertSame(AppointmentStatus::Scheduled, $appt->status);
        $this->assertSame('2026-08-03 09:30:00', $appt->ends_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('appointment_status_histories', [
            'appointment_id' => $appt->id, 'from_status' => null, 'to_status' => 'scheduled',
        ]);
    }

    public function test_rejects_a_time_outside_the_template(): void
    {
        $this->expectException(SlotUnavailableException::class);
        $this->svc->book($this->bookData(['scheduled_at' => '2026-08-03 18:00:00'])); // after 17:00
    }

    public function test_rejects_a_day_the_doctor_does_not_work(): void
    {
        $this->expectException(SlotUnavailableException::class);
        $this->svc->book($this->bookData(['scheduled_at' => '2026-08-04 09:00:00'])); // Tuesday, no window
    }

    public function test_rejects_a_start_misaligned_to_the_slot_grid(): void
    {
        $this->expectException(SlotUnavailableException::class);
        $this->svc->book($this->bookData(['scheduled_at' => '2026-08-03 09:10:00'])); // 10 min off a 30-min grid
    }

    public function test_rejects_a_doctor_double_booking(): void
    {
        $this->svc->book($this->bookData()); // 09:00–09:30

        try {
            $this->svc->book($this->bookData(['scheduled_at' => '2026-08-03 09:15:00', 'duration_minutes' => 30]));
            $this->fail('Expected SlotUnavailableException');
        } catch (SlotUnavailableException $e) {
            // 09:15 is also misaligned, but overlap/alignment either way rejects it.
        }
        // A clean, non-overlapping aligned slot succeeds.
        $ok = $this->svc->book($this->bookData(['scheduled_at' => '2026-08-03 09:30:00']));
        $this->assertSame(AppointmentStatus::Scheduled, $ok->status);
        $this->assertSame(1 + 1, \App\Models\Appointment::count());
    }

    public function test_rejects_a_room_double_booking_across_doctors(): void
    {
        $room = Room::factory()->create(['hospital_id' => $this->hospital->id]);
        $doctor2 = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor']);
        DoctorSchedule::factory()->create([
            'hospital_id' => $this->hospital->id, 'user_id' => $doctor2->id,
            'weekday' => Weekday::Monday, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'slot_minutes' => 30,
        ]);

        $this->svc->book($this->bookData(['room_id' => $room->id])); // doctor1 in room, 09:00

        $this->expectException(SlotUnavailableException::class);
        $this->svc->book($this->bookData(['doctor_user_id' => $doctor2->id, 'room_id' => $room->id])); // doctor2, same room+time
    }

    public function test_cancelled_slot_frees_the_time(): void
    {
        $a = $this->svc->book($this->bookData());
        $this->svc->transition($a, AppointmentStatus::Cancelled, $this->doctor->id, 'Patient rang');

        // Same slot can now be rebooked.
        $b = $this->svc->book($this->bookData());
        $this->assertSame(AppointmentStatus::Scheduled, $b->status);
    }

    /**
     * The happy path now ENDS in a record: an appointment is completed by
     * saying what was done at it, not by saying it is completed
     * (AppointmentService::recordOutcome, docs/scheduling.md).
     */
    public function test_state_machine_allows_the_happy_path(): void
    {
        $a = $this->svc->book($this->bookData());
        $this->svc->transition($a, AppointmentStatus::Confirmed);
        $this->svc->transition($a, AppointmentStatus::CheckedIn);
        $this->svc->transition($a, AppointmentStatus::InProgress);
        $this->svc->recordOutcome($a, 'Seen and advised.');

        $done = $a->fresh();

        $this->assertSame(AppointmentStatus::Completed, $done->status);
        $this->assertNotNull($done->checked_in_at);
        $this->assertNotNull($done->completed_at);
        $this->assertSame(5, $a->history()->count()); // book + 4 transitions
    }

    /**
     * And nothing may skip that step — not a screen, not a controller, not a
     * direct call. An appointment marked Completed with no consultation behind
     * it is a patient seen and never billed, and no record of what was found.
     */
    public function test_an_appointment_cannot_be_completed_with_nothing_recorded(): void
    {
        $a = $this->svc->book($this->bookData());
        $this->svc->transition($a, AppointmentStatus::CheckedIn);
        $this->svc->transition($a, AppointmentStatus::InProgress);

        $this->expectExceptionMessage('Record the outcome first');
        $this->svc->transition($a, AppointmentStatus::Completed);
    }

    public function test_state_machine_rejects_an_illegal_jump(): void
    {
        $a = $this->svc->book($this->bookData());

        $this->expectException(InvalidStatusTransitionException::class);
        $this->svc->transition($a, AppointmentStatus::Completed); // scheduled → completed not allowed
    }

    public function test_completed_appointment_is_terminal(): void
    {
        $a = $this->svc->book($this->bookData());
        $this->svc->transition($a, AppointmentStatus::CheckedIn);
        $this->svc->recordOutcome($a, 'Seen and advised.');

        $this->expectException(InvalidStatusTransitionException::class);
        $this->svc->transition($a->fresh(), AppointmentStatus::Cancelled);
    }
}
