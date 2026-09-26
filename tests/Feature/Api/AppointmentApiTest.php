<?php

namespace Tests\Feature\Api;

use App\Enums\Weekday;
use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppointmentApiTest extends TestCase
{
    use RefreshDatabase;

    /** Next Monday 09:00 — computed so the fixture never falls behind `after_or_equal:today`. */
    private string $mon0900;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->mon0900 = Carbon::now()->next(Carbon::MONDAY)->setTime(9, 0)->format('Y-m-d H:i');
        $this->assertSame(Weekday::Monday->value, Carbon::parse($this->mon0900)->dayOfWeek);
    }

    private function staff(Hospital $h, string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function doctorWithWindow(Hospital $h): User
    {
        app(CurrentHospital::class)->set($h->id);
        $doctor = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor']);
        DoctorSchedule::factory()->create([
            'hospital_id' => $h->id, 'user_id' => $doctor->id, 'weekday' => Weekday::Monday,
            'start_time' => '09:00:00', 'end_time' => '17:00:00', 'slot_minutes' => 30,
        ]);

        return $doctor;
    }

    public function test_book_then_advance_via_api(): void
    {
        $h = Hospital::factory()->create();
        $doctor = $this->doctorWithWindow($h);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        Sanctum::actingAs($this->staff($h, 'receptionist'));

        $res = $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id, 'doctor_user_id' => $doctor->id,
            'scheduled_at' => $this->mon0900, 'duration_minutes' => 30, 'source' => 'phone',
        ]);
        $res->assertStatus(201)->assertJsonPath('data.status', 'scheduled');
        $uuid = $res->json('data.uuid');

        $this->postJson("/api/v1/appointments/{$uuid}/transition", ['status' => 'checked_in'])
            ->assertOk()->assertJsonPath('data.status', 'checked_in');
    }

    public function test_double_booking_returns_422_in_the_envelope(): void
    {
        $h = Hospital::factory()->create();
        $doctor = $this->doctorWithWindow($h);
        $p1 = Patient::factory()->create(['hospital_id' => $h->id]);
        $p2 = Patient::factory()->create(['hospital_id' => $h->id]);
        Sanctum::actingAs($this->staff($h, 'receptionist'));

        $this->postJson('/api/v1/appointments', ['patient_id' => $p1->id, 'doctor_user_id' => $doctor->id, 'scheduled_at' => $this->mon0900, 'duration_minutes' => 30, 'source' => 'walk_in']);
        $this->postJson('/api/v1/appointments', ['patient_id' => $p2->id, 'doctor_user_id' => $doctor->id, 'scheduled_at' => $this->mon0900, 'duration_minutes' => 30, 'source' => 'walk_in'])
            ->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame(1, Appointment::count());
    }

    public function test_illegal_transition_returns_422(): void
    {
        $h = Hospital::factory()->create();
        $doctor = $this->doctorWithWindow($h);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        Sanctum::actingAs($this->staff($h, 'receptionist'));
        $uuid = $this->postJson('/api/v1/appointments', ['patient_id' => $patient->id, 'doctor_user_id' => $doctor->id, 'scheduled_at' => $this->mon0900, 'duration_minutes' => 30, 'source' => 'walk_in'])->json('data.uuid');

        $this->postJson("/api/v1/appointments/{$uuid}/transition", ['status' => 'completed'])
            ->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_index_scoped_and_filtered_by_date(): void
    {
        $h = Hospital::factory()->create();
        $doctor = $this->doctorWithWindow($h);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        Sanctum::actingAs($this->staff($h, 'receptionist'));
        $this->postJson('/api/v1/appointments', ['patient_id' => $patient->id, 'doctor_user_id' => $doctor->id, 'scheduled_at' => $this->mon0900, 'duration_minutes' => 30, 'source' => 'walk_in']);

        $this->getJson('/api/v1/appointments?date='.Carbon::parse($this->mon0900)->toDateString())->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/appointments?date='.Carbon::parse($this->mon0900)->addDay()->toDateString())->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_pharmacist_cannot_book(): void
    {
        $h = Hospital::factory()->create();
        $doctor = $this->doctorWithWindow($h);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        Sanctum::actingAs($this->staff($h, 'pharmacist'));

        $this->postJson('/api/v1/appointments', ['patient_id' => $patient->id, 'doctor_user_id' => $doctor->id, 'scheduled_at' => $this->mon0900, 'duration_minutes' => 30, 'source' => 'walk_in'])
            ->assertStatus(403);
    }

    /** The times offered are the times that book, and a moved appointment keeps its own. */
    public function test_availability_offers_times_that_book_and_rescheduling_moves(): void
    {
        $h = Hospital::factory()->create();
        $doctor = $this->doctorWithWindow($h);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        Sanctum::actingAs($this->staff($h, 'receptionist'));
        $monday = substr($this->mon0900, 0, 10);

        $this->getJson('/api/v1/booking/doctors')->assertOk()->assertJsonPath('data.0.id', $doctor->id);
        $free = $this->getJson("/api/v1/booking/availability?doctor={$doctor->id}&date={$monday}&duration=30")->assertOk();
        $this->assertSame('09:00', $free->json('data.times.0'));
        $this->assertCount(14, $free->json('data.days'));

        $uuid = $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id, 'doctor_user_id' => $doctor->id,
            'scheduled_at' => $this->mon0900, 'duration_minutes' => 30, 'source' => 'phone',
        ])->assertCreated()->json('data.uuid');

        $this->assertNotContains('09:00', $this->getJson("/api/v1/booking/availability?doctor={$doctor->id}&date={$monday}&duration=30")->json('data.times'));
        $this->assertContains('09:00', $this->getJson("/api/v1/booking/availability?doctor={$doctor->id}&date={$monday}&duration=30&ignore={$uuid}")->json('data.times'),
            'its own time stays free to the one being moved');

        $this->postJson("/api/v1/appointments/{$uuid}/reschedule", ['scheduled_at' => $monday.' 03:00', 'duration_minutes' => 30])->assertStatus(422);
        $this->postJson("/api/v1/appointments/{$uuid}/reschedule", ['scheduled_at' => $monday.' 10:00', 'duration_minutes' => 30])
            ->assertOk()->assertJsonPath('message', 'Appointment moved.');
    }
}
