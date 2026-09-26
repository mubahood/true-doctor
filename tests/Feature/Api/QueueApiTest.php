<?php

namespace Tests\Feature\Api;

use App\Enums\AppointmentStatus;
use App\Enums\Weekday;
use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/v1/queue is the web board's queue (App\Support\CheckInQueue):
 * the same lanes, order, clocks and thresholds.
 */
class QueueApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $doctor;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-16 10:00:00')); // a Wednesday

        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->clerk = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'receptionist']);
        $this->clerk->syncSpatieRole();
        $this->doctor = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor', 'name' => 'Dr Kasujja']);
        $this->doctor->syncSpatieRole();
        DoctorSchedule::create([
            'hospital_id' => $this->hospital->id, 'user_id' => $this->doctor->id,
            'weekday' => Weekday::Wednesday->value, 'start_time' => '08:00', 'end_time' => '17:00',
            'slot_minutes' => 30, 'is_active' => true,
        ]);
        Sanctum::actingAs($this->clerk);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function appointment(string $at, AppointmentStatus $status, ?string $checkedInAt = null, string $name = 'Amina'): Appointment
    {
        return Appointment::factory()->create([
            'hospital_id' => $this->hospital->id,
            'patient_id' => Patient::factory()->create(['hospital_id' => $this->hospital->id, 'first_name' => $name])->id,
            'doctor_user_id' => $this->doctor->id,
            'scheduled_at' => Carbon::parse($at),
            'ends_at' => Carbon::parse($at)->addMinutes(30),
            'status' => $status,
            'checked_in_at' => $checkedInAt === null ? null : Carbon::parse($checkedInAt),
        ]);
    }

    public function test_the_queue_has_the_boards_lanes_order_and_clocks(): void
    {
        $this->appointment('2026-09-16 09:30', AppointmentStatus::InProgress, '2026-09-16 09:20', 'Seeing');
        $this->appointment('2026-09-16 09:00', AppointmentStatus::CheckedIn, '2026-09-16 09:50', 'Recent');
        $this->appointment('2026-09-16 09:00', AppointmentStatus::CheckedIn, '2026-09-16 08:55', 'Longest');
        $this->appointment('2026-09-16 11:00', AppointmentStatus::Scheduled, null, 'Later');
        $this->appointment('2026-09-16 08:00', AppointmentStatus::Completed, '2026-09-16 07:55', 'Done');

        $data = $this->getJson('/api/v1/queue')->assertOk()->json('data');

        $this->assertSame('With the doctor', $data['lanes']['seeing']['title']);
        $this->assertCount(1, $data['lanes']['seeing']['rows']);

        // Waiting: longest first, with its clock and tone.
        $waiting = $data['lanes']['waiting']['rows'];
        $this->assertStringStartsWith('Longest', $waiting[0]['patient']['name']);
        $this->assertSame(65, $waiting[0]['waited_minutes']);
        $this->assertSame('1h 05m', $waiting[0]['waited_clock']);
        $this->assertSame('bad', $waiting[0]['wait_tone']);
        $this->assertSame('', $waiting[1]['wait_tone'], '10 minutes is not yet worth flagging');
        $this->assertContains('in_progress', $waiting[0]['next_statuses']);

        $this->assertSame(-60, $data['lanes']['expected']['rows'][0]['late_minutes'], 'an hour still to come');
        $this->assertSame(['expected' => 1, 'waiting' => 2, 'seeing' => 1, 'longest' => 65, 'seen' => 1], $data['tally']);
        $this->assertSame(['warn' => 20, 'bad' => 45], $data['thresholds']);
    }

    public function test_an_outcome_completes_the_appointment_with_the_webs_rule(): void
    {
        $appointment = $this->appointment('2026-09-16 09:30', AppointmentStatus::InProgress, '2026-09-16 09:20');
        Sanctum::actingAs($this->doctor);

        $this->postJson("/api/v1/appointments/{$appointment->uuid}/outcome", ['report' => 'x'])
            ->assertStatus(422)->assertJsonPath('errors.report.0', 'The report field must be at least 3 characters.');

        $this->postJson("/api/v1/appointments/{$appointment->uuid}/outcome", ['report' => 'Reviewed; BP controlled. Continue treatment.'])
            ->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_a_role_without_appointments_cannot_read_the_queue(): void
    {
        $pharmacist = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'pharmacist']);
        $pharmacist->syncSpatieRole();
        Sanctum::actingAs($pharmacist);

        $this->getJson('/api/v1/queue')->assertStatus(403);
    }
}
