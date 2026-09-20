<?php

namespace Tests\Feature\Api;

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VisitApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function staff(Hospital $h, string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    public function test_open_visit_and_get_number(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        Sanctum::actingAs($this->staff($h, 'receptionist'));

        $res = $this->postJson('/api/v1/visits', ['patient_id' => $patient->id, 'reason' => 'Fever']);

        $res->assertStatus(201)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.stage', 'ongoing')
            ->assertJsonPath('data.state', 'Pending');
        $this->assertMatchesRegularExpression('/^V-\d{8}-\d{3}$/', $res->json('data.visit_no'));
    }

    public function test_nurse_records_vitals_bmi_computed(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $c = Visit::factory()->create(['hospital_id' => $h->id]);
        Sanctum::actingAs($this->staff($h, 'nurse'));

        $res = $this->postJson("/api/v1/visits/{$c->uuid}/vitals", ['weight' => 80, 'height' => 178]);
        $res->assertOk()->assertJsonPath('data.vitals.bmi', '25.25');
    }

    public function test_nurse_cannot_diagnose_but_doctor_can(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $c = Visit::factory()->create(['hospital_id' => $h->id]);

        Sanctum::actingAs($this->staff($h, 'nurse'));
        $this->postJson("/api/v1/visits/{$c->uuid}/clinical", ['diagnosis' => 'Malaria'])->assertStatus(403);

        Sanctum::actingAs($this->staff($h, 'doctor'));
        $this->postJson("/api/v1/visits/{$c->uuid}/clinical", ['diagnosis' => 'Malaria'])
            ->assertOk()->assertJsonPath('data.diagnosis', 'Malaria');
    }

    /** The API reports the gate, not a menu of stages it might accept. */
    public function test_a_visit_reports_where_it_can_go_next(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $c = Visit::factory()->create(['hospital_id' => $h->id]);
        Sanctum::actingAs($this->staff($h, 'doctor'));

        $this->getJson("/api/v1/visits/{$c->uuid}")
            ->assertOk()
            ->assertJsonPath('data.stage', 'ongoing')
            ->assertJsonPath('data.next_stage', 'billing')
            ->assertJsonPath('data.outcome', null);
    }

    /** A shut gate is a 422, and it says what is holding it. */
    public function test_a_shut_gate_returns_422(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $c = Visit::factory()->create(['hospital_id' => $h->id]);
        Sanctum::actingAs($this->staff($h, 'receptionist'));

        // An open order holds the visit at Ongoing.
        app(\App\Services\VisitService::class)->start($c);
        app(\App\Services\OrderService::class)->place(
            $c->fresh(), \App\Enums\OrderType::Procedure, 'Dressing',
        );

        $this->postJson("/api/v1/visits/{$c->uuid}/transition", [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', '1 order is still open.');
    }

    public function test_an_open_gate_moves_the_visit(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $c = Visit::factory()->create(['hospital_id' => $h->id]);
        Sanctum::actingAs($this->staff($h, 'receptionist'));

        $this->postJson("/api/v1/visits/{$c->uuid}/transition", [])
            ->assertOk()
            ->assertJsonPath('data.stage', 'billing');
    }

    public function test_cross_hospital_visit_is_404(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $cB = Visit::factory()->create(['hospital_id' => $b->id]);
        Sanctum::actingAs($this->staff($a, 'doctor'));

        $this->getJson("/api/v1/visits/{$cB->uuid}")->assertStatus(404);
    }
}
