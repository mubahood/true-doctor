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

    // ── What the app needs ─────────────────────────────────────────────

    /** The web list's filters: the flattened state, the stage and the search. */
    public function test_the_list_filters_like_the_web_list(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $amina = Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Amina', 'last_name' => 'Nakato']);
        $other = Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Brian', 'last_name' => 'Okello']);
        $pending = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $amina->id]);
        $billing = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $other->id, 'status' => 'ongoing', 'stage' => 'billing']);
        $cancelled = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $other->id, 'status' => 'completed', 'outcome' => 'cancelled']);
        Sanctum::actingAs($this->staff($h, 'receptionist'));

        $uuids = fn (string $query) => collect($this->getJson('/api/v1/visits'.$query)->assertOk()->json('data'))->pluck('uuid')->all();

        $this->assertSame([$pending->uuid], $uuids('?q=nakato'));
        $this->assertSame([$pending->uuid], $uuids('?q='.$pending->visit_no));
        $this->assertSame([$billing->uuid], $uuids('?stage=billing'));
        $this->assertSame([$cancelled->uuid], $uuids('?status=cancelled'));
        $this->assertSame([$cancelled->uuid], $uuids('?status=completed'), 'the old raw filter still means every finished visit');
        $this->assertEqualsCanonicalizing([$pending->uuid, $billing->uuid], $uuids('?open=1'));
        $this->assertEqualsCanonicalizing([$billing->uuid, $cancelled->uuid], $uuids('?patient='.$other->uuid));
    }

    /** Each row says whether its gate is open, without a query per row. */
    public function test_each_row_says_whether_it_can_move_on(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $free = Visit::factory()->create(['hospital_id' => $h->id, 'status' => 'ongoing']);
        $held = Visit::factory()->create(['hospital_id' => $h->id, 'status' => 'ongoing']);
        app(\App\Services\OrderService::class)->place($held, \App\Enums\OrderType::Procedure, 'Dressing');
        Sanctum::actingAs($this->staff($h, 'receptionist'));

        $rows = collect($this->getJson('/api/v1/visits')->assertOk()->json('data'))->keyBy('uuid');

        $this->assertTrue($rows[$free->uuid]['advance_ready']);
        $this->assertFalse($rows[$held->uuid]['advance_ready']);
        $this->assertSame('info', $rows[$free->uuid]['state_tone']);
    }

    /** One visit carries its gate — with the reason it is shut — and its trail. */
    public function test_one_visit_carries_its_gate_and_its_history(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $patient = Patient::factory()->create(['hospital_id' => $h->id, 'allergies' => ['Penicillin']]);
        Sanctum::actingAs($user = $this->staff($h, 'doctor'));
        $visit = app(\App\Services\VisitService::class)->open(['patient_id' => $patient->id], $user->id);
        app(\App\Services\OrderService::class)->place($visit->fresh(), \App\Enums\OrderType::Procedure, 'Dressing');

        $this->getJson("/api/v1/visits/{$visit->uuid}")
            ->assertOk()
            ->assertJsonPath('data.patient.allergies', ['Penicillin'])
            ->assertJsonPath('data.gate.ready', false)
            ->assertJsonPath('data.gate.blocker', '1 order is still open.')
            ->assertJsonPath('data.gate.label', 'Ready for billing')
            ->assertJsonPath('data.advance_ready', false)
            ->assertJsonPath('data.history.0.to', fn ($to) => in_array($to, ['Ongoing', 'Pending'], true))
            ->assertJsonPath('data.version', fn ($v) => is_int($v) && $v >= 1);
    }

    /** The desk opens a visit with vitals and "being seen now", as the web dialog does. */
    public function test_opening_records_what_was_taken_at_the_desk(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        Sanctum::actingAs($this->staff($h, 'receptionist'));

        $res = $this->postJson('/api/v1/visits', [
            'patient_id' => $patient->id, 'reason' => 'Cough', 'diagnosis' => 'URTI',
            'temperature' => 38.2, 'weight' => 80, 'height' => 178, 'respiratory_rate' => 22, 'blood_pressure' => '',
            'start_now' => true,
        ])->assertCreated();

        $res->assertJsonPath('data.status', 'ongoing')
            ->assertJsonPath('data.diagnosis', 'URTI')
            ->assertJsonPath('data.vitals.bmi', '25.25')
            ->assertJsonPath('data.vitals.respiratory_rate', 22);
        $this->assertNotNull($res->json('data.vitals.recorded_at'));
        $this->assertCount(2, $res->json('data.history'), 'opened, then started — the start is in the trail, not simply appearing');
        $this->assertSame(['pending', 'ongoing'], \App\Models\VisitStatusHistory::orderBy('id')->pluck('to_status')->all());
    }

    /** Held to the web's ranges — one VisitVitalsRequest. */
    public function test_desk_vitals_are_held_to_the_web_ranges(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        Sanctum::actingAs($this->staff($h, 'receptionist'));

        $this->postJson('/api/v1/visits', ['patient_id' => $patient->id, 'temperature' => 60, 'blood_pressure' => '120-80'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['temperature', 'blood_pressure'], 'errors');
        $this->assertSame(0, Visit::count());
    }

    /** Somebody else's appointment cannot be the one a visit fulfils. */
    public function test_an_appointment_must_be_the_patients_own(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $theirs = \App\Models\Appointment::factory()->create(['hospital_id' => $h->id]);
        Sanctum::actingAs($this->staff($h, 'receptionist'));

        $this->postJson('/api/v1/visits', ['patient_id' => $patient->id, 'appointment_id' => $theirs->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.appointment_id.0', 'That appointment is not this patient\'s, or already has a visit.');
    }

    /** New patient and visit together — the web dialog's other tab. */
    public function test_intake_registers_the_patient_and_opens_the_visit(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        Sanctum::actingAs($this->staff($h, 'receptionist'));

        $res = $this->postJson('/api/v1/visits/intake', [
            'first_name' => 'Grace', 'last_name' => 'Atim', 'sex' => 'female', 'phone_1' => '0772000111',
            'reason' => 'Headache', 'diagnosis' => 'Migraine', 'pulse' => 88, 'consent_given' => true,
        ])->assertCreated();

        $res->assertJsonPath('data.patient.name', 'Grace Atim')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.diagnosis', 'Migraine')
            ->assertJsonPath('data.vitals.pulse', 88);
        $patient = Patient::where('uuid', $res->json('data.patient.uuid'))->firstOrFail();
        $this->assertTrue((bool) $patient->consent_given);
        $this->assertMatchesRegularExpression('/^V-\d{8}-\d{3}$/', $res->json('data.visit_no'));
    }

    public function test_intake_is_validated_and_writes_nothing_when_refused(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        Sanctum::actingAs($this->staff($h, 'receptionist'));

        $this->postJson('/api/v1/visits/intake', ['last_name' => 'Atim', 'dob' => now()->addDay()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'dob'], 'errors');
        $this->assertSame(0, Patient::count());
        $this->assertSame(0, Visit::count());
    }

    public function test_intake_needs_both_abilities(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $role = collect(['lab_technician', 'pharmacist', 'accountant'])
            ->first(fn ($r) => ! $this->staff($h, $r)->can('patients.create'));
        $this->assertNotNull($role);
        Sanctum::actingAs($this->staff($h, $role));

        $this->postJson('/api/v1/visits/intake', ['first_name' => 'Grace', 'last_name' => 'Atim'])->assertStatus(403);
        $this->assertSame(0, Patient::count());
    }

    /** Cancelling takes a reason, in the web's words, and says so in the trail. */
    public function test_cancelling_takes_a_reason(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $visit = Visit::factory()->create(['hospital_id' => $h->id]);
        Sanctum::actingAs($this->staff($h, 'receptionist'));

        $this->postJson("/api/v1/visits/{$visit->uuid}/cancel", ['note' => ''])
            ->assertStatus(422)
            ->assertJsonPath('errors.note.0', 'Say why the visit is being called off.');

        $this->postJson("/api/v1/visits/{$visit->uuid}/cancel", ['note' => 'Patient left before being seen'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.outcome', 'cancelled')
            ->assertJsonPath('data.is_open', false)
            ->assertJsonPath('data.gate.ready', false)
            ->assertJsonPath('data.history.0.note', 'Patient left before being seen');

        $this->postJson("/api/v1/visits/{$visit->uuid}/cancel", ['note' => 'Again'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This visit is already finished.');
    }

    /** The web dialog's brief: an open visit, today's booking, money owed. */
    public function test_the_brief_warns_before_a_second_visit(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $open = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $patient->id]);
        $booking = \App\Models\Appointment::factory()->create([
            'hospital_id' => $h->id, 'patient_id' => $patient->id, 'status' => 'scheduled',
            'scheduled_at' => now()->setTime(23, 0), 'ends_at' => now()->setTime(23, 30),
        ]);
        Sanctum::actingAs($this->staff($h, 'receptionist'));

        $this->getJson("/api/v1/patients/{$patient->uuid}/brief")
            ->assertOk()
            ->assertJsonPath('data.open_visit.visit_no', $open->visit_no)
            ->assertJsonPath('data.appointment.uuid', $booking->uuid)
            ->assertJsonPath('data.appointment.at', '23:00')
            ->assertJsonPath('data.owed', '0.00');

        // The booking is fulfilled once, and the brief stops offering it.
        $this->postJson('/api/v1/visits', ['patient_id' => $patient->id, 'appointment_id' => $booking->id])->assertCreated();
        $this->getJson("/api/v1/patients/{$patient->uuid}/brief")->assertJsonPath('data.appointment', null);
        $this->postJson('/api/v1/visits', ['patient_id' => $patient->id, 'appointment_id' => $booking->id])
            ->assertStatus(422)->assertJsonValidationErrors(['appointment_id'], 'errors');
    }
}
