<?php

namespace Tests\Feature\Api;

use App\Models\Admission;
use App\Models\Bed;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdmissionDeskApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    private Ward $ward;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->h->id);
        $this->ward = Ward::factory()->create(['hospital_id' => $this->h->id, 'name' => 'Male ward']);
    }

    private function user(string $role, ?Hospital $h = null): User
    {
        $u = User::factory()->create(['hospital_id' => ($h ?? $this->h)->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function bed(string $name, ?Ward $ward = null): Bed
    {
        $ward ??= $this->ward;

        return Bed::factory()->create(['hospital_id' => $ward->hospital_id, 'ward_id' => $ward->id, 'name' => $name, 'daily_charge' => '50.00']);
    }

    private function visitFor(Patient $p): Visit
    {
        return Visit::factory()->create(['hospital_id' => $p->hospital_id, 'patient_id' => $p->id]);
    }

    private function admit(string $first = 'Amina', ?Bed $bed = null): Admission
    {
        $p = Patient::factory()->create(['hospital_id' => $this->h->id, 'first_name' => $first]);

        return app(AdmissionService::class)->admit($p, $bed ?? $this->bed('M-'.$first), ['visit_id' => $this->visitFor($p)->id, 'reason' => 'Malaria'], $this->user('nurse')->id);
    }

    public function test_list_defaults_to_admitted_and_filters_by_status_ward_and_search(): void
    {
        $a = $this->admit('Amina');
        $this->admit('Brian', $this->bed('F-1', Ward::factory()->create(['hospital_id' => $this->h->id, 'name' => 'Female ward'])));
        $closed = $this->admit('Cathy');
        app(AdmissionService::class)->discharge($closed, \App\Enums\AdmissionStatus::Discharged, null, null);
        Sanctum::actingAs($this->user('nurse'));

        $this->getJson('/api/v1/admissions')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/admissions?status=discharged')->assertJsonCount(1, 'data')->assertJsonPath('data.0.patient.name', $closed->patient->full_name);
        $this->getJson('/api/v1/admissions?ward='.$this->ward->id)->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $a->uuid);
        $this->getJson('/api/v1/admissions?q=Brian')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admissions?status=nonsense')->assertStatus(422);
    }

    public function test_show_carries_stay_bedside_summary_and_transfers(): void
    {
        $a = $this->admit('Amina');
        $nurse = $this->user('nurse');
        Sanctum::actingAs($nurse);

        $this->postJson("/api/v1/admissions/{$a->uuid}/vitals", ['temperature' => '38.2', 'pulse' => 96, 'blood_pressure' => '120/80'])->assertStatus(201)->assertJsonPath('data.pulse', 96);
        $this->postJson("/api/v1/admissions/{$a->uuid}/medications", ['drug_name' => 'Artemether', 'dose' => '80mg', 'status' => 'given'])->assertStatus(201);
        $this->postJson("/api/v1/admissions/{$a->uuid}/notes", ['note' => 'Rested well'])->assertStatus(201)->assertJsonPath('data.recorded_by', $nurse->name);

        $res = $this->getJson("/api/v1/admissions/{$a->uuid}")->assertOk();
        $res->assertJsonPath('data.ward.name', 'Male ward')
            ->assertJsonPath('data.bed.daily_charge', '50.00')
            ->assertJsonPath('data.nights', 1)
            ->assertJsonPath('data.accrued_so_far', '50.00')
            ->assertJsonPath('data.bedside.latest_vitals.temperature', '38.2')
            ->assertJsonPath('data.bedside.vitals_count', 1)
            ->assertJsonPath('data.bedside.medication_count', 1)
            ->assertJsonPath('data.bedside.nursing_notes_count', 1)
            ->assertJsonPath('data.visit.uuid', $a->visit->uuid)
            ->assertJsonPath('data.can_manage', true);

        $this->getJson("/api/v1/admissions/{$a->uuid}/vitals")->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/admissions/{$a->uuid}/medications")->assertJsonPath('data.0.drug_name', 'Artemether');
        $this->getJson("/api/v1/admissions/{$a->uuid}/notes")->assertJsonPath('data.0.note', 'Rested well');

        $this->postJson("/api/v1/admissions/{$a->uuid}/vitals", ['pulse' => 5])->assertStatus(422);
        $this->postJson("/api/v1/admissions/{$a->uuid}/medications", ['dose' => '1'])->assertStatus(422);
    }

    public function test_a_closed_admission_refuses_new_chart_entries(): void
    {
        $a = $this->admit('Amina');
        app(AdmissionService::class)->discharge($a, \App\Enums\AdmissionStatus::Discharged, null, null);
        Sanctum::actingAs($this->user('nurse'));

        $this->postJson("/api/v1/admissions/{$a->uuid}/notes", ['note' => 'late'])->assertStatus(422)->assertJsonPath('message', 'This admission is closed.');
        $this->getJson("/api/v1/admissions/{$a->uuid}")->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_admit_onto_a_visit_with_the_services_rules(): void
    {
        $p = Patient::factory()->create(['hospital_id' => $this->h->id]);
        $visit = $this->visitFor($p);
        $bed = $this->bed('M-1');
        $doctor = $this->user('doctor');
        Sanctum::actingAs($this->user('nurse'));

        $res = $this->postJson('/api/v1/admissions', ['visit_id' => $visit->id, 'bed_id' => $bed->id, 'admitting_doctor_id' => $doctor->id, 'reason' => 'Pneumonia', 'stay_title' => 'Chest ward stay'])
            ->assertStatus(201)->assertJsonPath('data.status', 'admitted')->assertJsonPath('data.bed.name', 'M-1')->assertJsonPath('data.admitting_doctor.id', $doctor->id);
        $this->assertSame('occupied', $bed->fresh()->status->value);
        $this->assertDatabaseHas('orders', ['visit_id' => $visit->id, 'title' => 'Chest ward stay']);

        // The bed is taken, and the patient is already in a bed.
        $q = Patient::factory()->create(['hospital_id' => $this->h->id]);
        $this->postJson('/api/v1/admissions', ['visit_id' => $this->visitFor($q)->id, 'bed_id' => $bed->id])->assertStatus(422)->assertJsonValidationErrors('bed_id');
        $this->postJson('/api/v1/admissions', ['visit_id' => $visit->id, 'bed_id' => $this->bed('M-2')->id])->assertStatus(422)->assertJsonValidationErrors('visit_id');
        $this->postJson('/api/v1/admissions', ['bed_id' => $bed->id])->assertStatus(422)->assertJsonValidationErrors('visit_id');
        $this->assertSame(1, Admission::count());
        $this->assertNotNull($res->json('data.uuid'));
    }

    public function test_roles_without_ipd_cannot_read_and_without_manage_cannot_write(): void
    {
        $a = $this->admit('Amina');
        $bed = $this->bed('M-9');
        $visit = $this->visitFor(Patient::factory()->create(['hospital_id' => $this->h->id]));

        Sanctum::actingAs($this->user('receptionist'));
        $this->getJson('/api/v1/admissions')->assertForbidden();
        $this->getJson("/api/v1/admissions/{$a->uuid}")->assertForbidden();
        $this->getJson("/api/v1/admissions/{$a->uuid}/vitals")->assertForbidden();
        $this->postJson('/api/v1/admissions', ['visit_id' => $visit->id, 'bed_id' => $bed->id])->assertForbidden();
        $this->postJson("/api/v1/admissions/{$a->uuid}/notes", ['note' => 'x'])->assertForbidden();
    }

    public function test_another_hospitals_admissions_are_not_found(): void
    {
        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $oWard = Ward::factory()->create(['hospital_id' => $other->id]);
        $oBed = Bed::factory()->create(['hospital_id' => $other->id, 'ward_id' => $oWard->id]);
        $op = Patient::factory()->create(['hospital_id' => $other->id]);
        $foreign = app(AdmissionService::class)->admit($op, $oBed, ['visit_id' => Visit::factory()->create(['hospital_id' => $other->id, 'patient_id' => $op->id])->id], null);
        $foreignVisit = Visit::factory()->create(['hospital_id' => $other->id, 'patient_id' => $op->id]);
        app(CurrentHospital::class)->set($this->h->id);
        $mine = $this->admit('Amina');

        Sanctum::actingAs($this->user('nurse'));
        $this->getJson('/api/v1/admissions')->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $mine->uuid);
        $this->getJson("/api/v1/admissions/{$foreign->uuid}")->assertNotFound();
        $this->getJson("/api/v1/admissions/{$foreign->uuid}/notes")->assertNotFound();
        $this->postJson("/api/v1/admissions/{$foreign->uuid}/notes", ['note' => 'x'])->assertNotFound();
        $this->postJson('/api/v1/admissions', ['visit_id' => $foreignVisit->id, 'bed_id' => $this->bed('M-5')->id])->assertStatus(422)->assertJsonValidationErrors('visit_id');
        $this->postJson('/api/v1/admissions', ['visit_id' => $this->visitFor(Patient::factory()->create(['hospital_id' => $this->h->id]))->id, 'bed_id' => $oBed->id])->assertStatus(422)->assertJsonValidationErrors('bed_id');
    }
}
