<?php

namespace Tests\Feature\Api;

use App\Enums\Weekday;
use App\Models\DoctorSchedule;
use App\Models\Hospital;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScheduleApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->h->id);
        $this->doctor = $this->user('doctor', $this->h);
    }

    private function user(string $role, Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function window(array $over = []): DoctorSchedule
    {
        return DoctorSchedule::factory()->create($over + ['hospital_id' => $this->h->id, 'user_id' => $this->doctor->id]);
    }

    public function test_roster_lists_doctors_with_a_seven_day_week_and_facts(): void
    {
        $this->window(['weekday' => Weekday::Thursday, 'start_time' => '08:00:00', 'end_time' => '12:00:00']);
        $idle = $this->user('doctor', $this->h);
        Sanctum::actingAs($this->user('receptionist', $this->h));

        $res = $this->getJson('/api/v1/schedules')->assertOk();
        $this->assertCount(2, $res->json('data'));
        $row = collect($res->json('data'))->firstWhere('id', $this->doctor->id);
        $this->assertCount(7, $row['week']);
        $this->assertSame('Monday', $row['week'][0]['label']);
        $thu = collect($row['week'])->firstWhere('weekday', 4);
        $this->assertSame('08:00', $thu['windows'][0]['start_time']);
        $this->assertSame(8, $thu['windows'][0]['slots']);
        $this->assertEquals(4, $row['hours_per_week']);
        $res->assertJsonPath('meta.facts.unrostered', 1)->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/schedules?unrostered=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $idle->id);
        $this->getJson('/api/v1/schedules?q=zzzz')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/schedules/doctors/'.$this->doctor->id)->assertOk()->assertJsonCount(7, 'data.week');
    }

    public function test_list_view_filters_by_day_and_state_and_flags_overlaps(): void
    {
        $a = $this->window(['weekday' => Weekday::Monday, 'start_time' => '08:00:00', 'end_time' => '12:00:00']);
        $b = $this->window(['weekday' => Weekday::Monday, 'start_time' => '11:00:00', 'end_time' => '14:00:00']);
        $this->window(['weekday' => Weekday::Tuesday, 'is_active' => false]);
        Sanctum::actingAs($this->user('nurse', $this->h));

        $res = $this->getJson('/api/v1/schedules?view=list')->assertOk()->assertJsonCount(3, 'data');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $res->json('meta.facts.overlapping_window_ids'));
        $this->getJson('/api/v1/schedules?view=list&day=2')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/schedules?view=list&state=off')->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_active', false);
        $this->getJson('/api/v1/schedules?day=9')->assertStatus(422);
    }

    public function test_an_admin_adds_a_repeating_window_edits_toggles_and_removes_it(): void
    {
        Sanctum::actingAs($this->user('hospital_admin', $this->h));
        $body = ['user_id' => $this->doctor->id, 'weekday' => 'weekdays', 'start_time' => '08:00', 'end_time' => '12:00', 'slot_minutes' => 20];

        $this->postJson('/api/v1/schedules', $body)->assertStatus(201)->assertJsonPath('data.added', 5)->assertJsonPath('data.skipped', 0);
        $this->postJson('/api/v1/schedules', $body)->assertOk()->assertJsonPath('data.added', 0)->assertJsonPath('data.skipped', 5);
        $this->postJson('/api/v1/schedules', ['weekday' => 3] + $body + [])->assertOk()->assertJsonPath('data.skipped', 1);
        $this->postJson('/api/v1/schedules', ['weekday' => 6, 'end_time' => '07:00'] + $body)->assertStatus(422)->assertJsonValidationErrors('end_time');
        $this->postJson('/api/v1/schedules', ['weekday' => 'fortnightly'] + $body)->assertStatus(422);

        $w = DoctorSchedule::where('weekday', 1)->first();
        $this->putJson("/api/v1/schedules/{$w->id}", ['user_id' => $this->doctor->id, 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '13:00', 'slot_minutes' => 30])
            ->assertOk()->assertJsonPath('data.start_time', '09:00')->assertJsonPath('data.slot_minutes', 30);
        $this->postJson("/api/v1/schedules/{$w->id}/toggle")->assertOk()->assertJsonPath('data.is_active', false);
        $this->deleteJson("/api/v1/schedules/{$w->id}")->assertOk();
        $this->assertDatabaseMissing('doctor_schedules', ['id' => $w->id]);
    }

    public function test_reading_roles_cannot_write(): void
    {
        $w = $this->window();
        $body = ['user_id' => $this->doctor->id, 'weekday' => 2, 'start_time' => '08:00', 'end_time' => '12:00', 'slot_minutes' => 30];

        foreach (['receptionist', 'doctor', 'nurse'] as $role) {
            Sanctum::actingAs($this->user($role, $this->h));
            $this->postJson('/api/v1/schedules', $body)->assertForbidden();
            $this->putJson("/api/v1/schedules/{$w->id}", $body)->assertForbidden();
            $this->postJson("/api/v1/schedules/{$w->id}/toggle")->assertForbidden();
            $this->deleteJson("/api/v1/schedules/{$w->id}")->assertForbidden();
        }
        $this->assertDatabaseHas('doctor_schedules', ['id' => $w->id]);
    }

    public function test_a_role_without_appointments_view_cannot_read(): void
    {
        Sanctum::actingAs($this->user('pharmacist', $this->h));
        $this->getJson('/api/v1/schedules')->assertForbidden();
    }

    public function test_another_hospital_is_invisible_and_untouchable(): void
    {
        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $foreignDoc = $this->user('doctor', $other);
        $foreign = DoctorSchedule::factory()->create(['hospital_id' => $other->id, 'user_id' => $foreignDoc->id]);
        app(CurrentHospital::class)->set($this->h->id);

        Sanctum::actingAs($admin = $this->user('hospital_admin', $this->h));
        $this->getJson('/api/v1/schedules')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/schedules?view=list')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/schedules/doctors/'.$foreignDoc->id)->assertNotFound();
        $this->putJson("/api/v1/schedules/{$foreign->id}", ['user_id' => $this->doctor->id, 'weekday' => 1, 'start_time' => '08:00', 'end_time' => '09:00', 'slot_minutes' => 30])->assertNotFound();
        $this->deleteJson("/api/v1/schedules/{$foreign->id}")->assertNotFound();
        $this->postJson('/api/v1/schedules', ['user_id' => $foreignDoc->id, 'weekday' => 1, 'start_time' => '08:00', 'end_time' => '09:00', 'slot_minutes' => 30])
            ->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->assertDatabaseHas('doctor_schedules', ['id' => $foreign->id]);
    }
}
