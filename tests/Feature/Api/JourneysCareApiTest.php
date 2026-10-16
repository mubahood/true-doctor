<?php

namespace Tests\Feature\Api;

use App\Models\Admission;
use App\Models\Bed;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Visit;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Services\DispensationService;
use App\Services\PrescriptionService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** W2-D / W2-E / W2-F / W2-G: the dispense queue, last prescription, the ward round, handover, duplicate check. */
class JourneysCareApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->h = Hospital::factory()->create(['timezone' => 'UTC']);
        app(CurrentHospital::class)->set($this->h->id);
    }

    private function staff(string $role, ?Hospital $h = null): User
    {
        $u = User::factory()->create(['hospital_id' => ($h ?? $this->h)->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function visit(?Patient $patient = null): Visit
    {
        return Visit::factory()->create(['hospital_id' => $this->h->id, 'patient_id' => ($patient ?? Patient::factory()->create(['hospital_id' => $this->h->id]))->id, 'status' => 'ongoing']);
    }

    /** @return array{0:Ward,1:Admission,2:Patient} */
    private function admitted(string $name = 'Amina Nakato', string $wardName = 'Female ward'): array
    {
        [$first, $last] = explode(' ', $name);
        $ward = Ward::factory()->create(['hospital_id' => $this->h->id, 'name' => $wardName]);
        $bed = Bed::factory()->create(['hospital_id' => $this->h->id, 'ward_id' => $ward->id, 'name' => 'F-1']);
        $patient = Patient::factory()->create(['hospital_id' => $this->h->id, 'first_name' => $first, 'last_name' => $last, 'allergies' => ['Penicillin']]);
        $admission = app(AdmissionService::class)->admit($patient, $bed, ['reason' => 'Severe malaria'], $this->staff('nurse')->id);

        return [$ward, $admission, $patient];
    }

    public function test_the_dispense_queue_lists_undispensed_lines_with_stock_and_shortfall(): void
    {
        $visit = $this->visit();
        StockItem::factory()->create(['hospital_id' => $this->h->id, 'name' => 'Amoxicillin 500mg', 'current_quantity' => '30.00']);
        StockItem::factory()->create(['hospital_id' => $this->h->id, 'name' => 'Paracetamol', 'current_quantity' => '4.00']);
        $rx = app(PrescriptionService::class)->prescribe($visit, [
            ['drug_name' => 'Amoxicillin 500mg', 'dosage' => '1 tab', 'slots' => ['morning', 'evening'], 'days' => 5],
            ['drug_name' => 'Paracetamol', 'dosage' => '2 tabs', 'slots' => ['morning', 'afternoon', 'evening'], 'days' => 2],
            ['drug_name' => 'Unstocked syrup', 'slots' => ['night'], 'days' => 1],
        ], null, null);

        $this->getJson('/api/v1/prescriptions?status=pending')->assertStatus(401);
        Sanctum::actingAs($this->staff('doctor'));
        $this->getJson('/api/v1/prescriptions?status=pending')->assertStatus(403);

        Sanctum::actingAs($this->staff('pharmacist'));
        $this->travel(25)->minutes();
        $r = $this->getJson('/api/v1/prescriptions?status=pending')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.uuid', $rx->uuid)
            ->assertJsonPath('data.0.visit_uuid', $visit->uuid)
            ->assertJsonPath('data.0.patient.patient_no', $visit->patient->patient_no)
            ->assertJsonPath('data.0.waited_minutes', 25)
            ->assertJsonPath('data.0.items.0.drug_name', 'Amoxicillin 500mg')
            ->assertJsonPath('data.0.items.0.qty', 10)
            ->assertJsonPath('data.0.items.0.dose', '1 tab')
            ->assertJsonPath('data.0.items.0.stock_available', '30.00')
            ->assertJsonPath('data.0.items.0.short', false)
            ->assertJsonPath('data.0.items.1.qty', 6)
            ->assertJsonPath('data.0.items.1.short', true)
            ->assertJsonPath('data.0.items.2.stock_item_uuid', null)
            ->assertJsonPath('data.0.items.2.stock_available', null)
            ->assertJsonPath('data.0.items.2.short', false);
        $this->assertNotNull($r->json('data.0.items.0.stock_item_uuid'));
        $this->getJson('/api/v1/prescriptions?status=archived')->assertStatus(422);

        // Dispensed under the prescription: it leaves the queue.
        $amox = StockItem::where('name', 'Amoxicillin 500mg')->first();
        app(DispensationService::class)->dispense($visit, [['stock_item_id' => $amox->id, 'quantity' => '10.00']], null, null, $rx->id);
        $this->getJson('/api/v1/prescriptions?status=pending')->assertOk()->assertJsonPath('meta.total', 0);
        $this->travelBack();

        // Another hospital sees none of it.
        $other = Hospital::factory()->create();
        Sanctum::actingAs($this->staff('pharmacist', $other));
        app(CurrentHospital::class)->set($other->id);
        $this->getJson('/api/v1/prescriptions?status=pending')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_a_partly_dispensed_prescription_keeps_only_its_open_lines(): void
    {
        $visit = $this->visit();
        $a = StockItem::factory()->create(['hospital_id' => $this->h->id, 'name' => 'Zinc', 'current_quantity' => '90.00']);
        app(PrescriptionService::class)->prescribe($visit, [
            ['drug_name' => 'Zinc', 'slots' => ['morning'], 'days' => 10],
            ['drug_name' => 'ORS', 'slots' => ['morning'], 'days' => 3],
        ], null, null);
        app(DispensationService::class)->dispense($visit, [['stock_item_id' => $a->id, 'quantity' => '10.00']], null, null);

        Sanctum::actingAs($this->staff('pharmacist'));
        $r = $this->getJson('/api/v1/prescriptions')->assertOk();
        $this->assertSame(['ORS'], array_column($r->json('data.0.items'), 'drug_name'));
    }

    public function test_last_prescription_is_the_latest_and_null_when_there_is_none(): void
    {
        $patient = Patient::factory()->create(['hospital_id' => $this->h->id]);
        $this->getJson("/api/v1/patients/{$patient->uuid}/last-prescription")->assertStatus(401);

        Sanctum::actingAs($this->staff('doctor'));
        $this->getJson("/api/v1/patients/{$patient->uuid}/last-prescription")->assertOk()->assertJsonPath('data.prescription', null);

        $old = $this->visit($patient);
        $new = $this->visit($patient);
        app(PrescriptionService::class)->prescribe($old, [['drug_name' => 'Old drug', 'slots' => ['morning'], 'days' => 1]], null, null);
        $this->travel(1)->minute();
        app(PrescriptionService::class)->prescribe($new, [['drug_name' => 'Coartem', 'dosage' => '4 tabs', 'slots' => ['morning', 'night'], 'days' => 3, 'instructions' => 'With food']], null, null);

        $this->getJson("/api/v1/patients/{$patient->uuid}/last-prescription")->assertOk()
            ->assertJsonPath('data.prescription.items.0.drug_name', 'Coartem')
            ->assertJsonPath('data.prescription.items.0.dose', '4 tabs')
            ->assertJsonPath('data.prescription.items.0.days', 3)
            ->assertJsonPath('data.prescription.items.0.slots', ['morning', 'night'])
            ->assertJsonPath('data.prescription.items.0.instructions', 'With food');
        $this->getJson("/api/v1/patients/{$patient->uuid}/last-prescription?visit={$new->uuid}")->assertOk()
            ->assertJsonPath('data.prescription.items.0.drug_name', 'Old drug');

        Sanctum::actingAs($this->staff('records_officer')); // no visits.view
        $this->getJson("/api/v1/patients/{$patient->uuid}/last-prescription")->assertStatus(403);

        $other = Hospital::factory()->create();
        Sanctum::actingAs($this->staff('doctor', $other));
        app(CurrentHospital::class)->set($other->id);
        $this->getJson("/api/v1/patients/{$patient->uuid}/last-prescription")->assertStatus(404);
    }

    public function test_doses_due_lists_overdue_and_coming_doses_of_admitted_patients(): void
    {
        $this->travelTo(now()->setTime(9, 0)); // 08:00 morning dose is 60 min late; 13:00 is 240 min away
        [$ward, $admission] = $this->admitted();
        app(PrescriptionService::class)->prescribe($admission->visit, [
            ['drug_name' => 'Quinine', 'dosage' => '600mg', 'slots' => ['morning', 'afternoon'], 'days' => 1],
        ], null, null);
        // A discharged patient's doses are not on the round.
        [, $gone] = $this->admitted('John Okello', 'Male ward');
        app(PrescriptionService::class)->prescribe($gone->visit, [['drug_name' => 'Hidden', 'slots' => ['morning'], 'days' => 1]], null, null);
        $gone->update(['status' => 'discharged']);

        $this->getJson('/api/v1/ward/doses-due')->assertStatus(401);
        Sanctum::actingAs($this->staff('receptionist'));
        $this->getJson('/api/v1/ward/doses-due')->assertStatus(403);

        Sanctum::actingAs($this->staff('nurse'));
        $r = $this->getJson('/api/v1/ward/doses-due?within=60')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.admission_uuid', $admission->uuid)
            ->assertJsonPath('data.0.patient.name', 'Amina Nakato')
            ->assertJsonPath('data.0.bed.name', 'F-1')
            ->assertJsonPath('data.0.drug', 'Quinine')
            ->assertJsonPath('data.0.dose', '600mg')
            ->assertJsonPath('data.0.slot', 'morning')
            ->assertJsonPath('data.0.overdue_minutes', 60)
            ->assertJsonStructure(['data' => [['due_at', 'dose_record_id']]]);

        $this->getJson('/api/v1/ward/doses-due?within=300')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.overdue_minutes', 0);
        $this->getJson("/api/v1/ward/doses-due?within=300&ward_uuid={$ward->id}")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/ward/doses-due?within=9999')->assertStatus(422);

        // Giving it through the existing endpoint clears it from the round.
        $this->postJson('/api/v1/doses/'.$r->json('data.0.dose_record_id'), ['status' => 'administered'])->assertOk();
        $this->getJson('/api/v1/ward/doses-due?within=60')->assertOk()->assertJsonCount(0, 'data');

        $other = Hospital::factory()->create();
        Sanctum::actingAs($this->staff('nurse', $other));
        app(CurrentHospital::class)->set($other->id);
        $this->getJson('/api/v1/ward/doses-due?within=300')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/ward/doses-due?ward_uuid={$ward->id}")->assertStatus(404);
        $this->travelBack();
    }

    public function test_handover_seed_save_acknowledge_history_and_pdf(): void
    {
        [$ward, $admission, $patient] = $this->admitted();
        $nurse = $this->staff('nurse');
        $admission->vitalRounds()->create(['temperature' => 37.0, 'pulse' => 80, 'spo2' => 98, 'recorded_by' => $nurse->id, 'created_at' => now()->subHours(5)]);
        $admission->vitalRounds()->create(['temperature' => 39.2, 'pulse' => 125, 'spo2' => 90, 'blood_pressure' => '110/70', 'recorded_by' => $nurse->id, 'created_at' => now()->subHour()]);
        $admission->nursingNotes()->create(['note' => 'Vomited twice', 'recorded_by' => $nurse->id, 'created_at' => now()->subMinutes(30)]);

        $this->getJson('/api/v1/ward/handover')->assertStatus(401);
        Sanctum::actingAs($this->staff('receptionist'));
        $this->getJson('/api/v1/ward/handover')->assertStatus(403);

        Sanctum::actingAs($nurse);
        $seed = $this->getJson("/api/v1/ward/handover?ward_uuid={$ward->id}")->assertOk()
            ->assertJsonPath('data.0.admission_uuid', $admission->uuid)
            ->assertJsonPath('data.0.patient.name', 'Amina Nakato')
            ->assertJsonPath('data.0.bed.name', 'F-1')
            ->assertJsonPath('data.0.situation.reason', 'Severe malaria')
            ->assertJsonPath('data.0.background.allergies', ['Penicillin'])
            ->assertJsonPath('data.0.assessment.trend', 'worsening')
            ->assertJsonPath('data.0.assessment.last_vitals.pulse', 125)
            ->assertJsonPath('data.0.recommendation.notes_since_last_handover', ['Vomited twice']);
        $this->assertContains('low oxygen', $seed->json('data.0.assessment.flags'));
        $this->assertStringContainsString('Penicillin', $seed->json('data.0.background.text'));

        $payload = ['ward_uuid' => $ward->id, 'shift' => 'morning', 'notes' => [[
            'admission_uuid' => $admission->uuid,
            'situation' => $seed->json('data.0.situation.text'),
            'background' => $seed->json('data.0.background.text'),
            'assessment' => $seed->json('data.0.assessment.text'),
            'recommendation' => 'Review SpO2 at 14:00',
        ]]];
        $this->postJson('/api/v1/ward/handover', ['shift' => 'dawn'] + $payload)->assertStatus(422);
        $this->postJson('/api/v1/ward/handover', ['notes' => [['admission_uuid' => 'nope']]] + $payload)->assertStatus(422);
        $saved = $this->postJson('/api/v1/ward/handover', $payload)->assertCreated()
            ->assertJsonPath('data.shift', 'morning')
            ->assertJsonPath('data.ward.name', 'Female ward')
            ->assertJsonPath('data.notes.0.patient.name', 'Amina Nakato')
            ->assertJsonPath('data.notes.0.recommendation', 'Review SpO2 at 14:00')
            ->assertJsonPath('data.acknowledged_at', null);
        $id = $saved->json('data.uuid');

        // Notes since the last handover start again.
        $this->getJson("/api/v1/ward/handover?ward_uuid={$ward->id}")->assertOk()->assertJsonPath('data.0.recommendation.notes_since_last_handover', []);

        // The one who wrote it cannot take it over; the incoming nurse can, and a retry is harmless.
        $this->postJson("/api/v1/ward/handover/{$id}/acknowledge")->assertStatus(422);
        $incoming = $this->staff('nurse');
        Sanctum::actingAs($incoming);
        $this->postJson("/api/v1/ward/handover/{$id}/acknowledge")->assertOk()->assertJsonPath('data.acknowledged_by', $incoming->name);
        $this->postJson("/api/v1/ward/handover/{$id}/acknowledge")->assertOk();

        $this->getJson('/api/v1/ward/handover/history')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.uuid', $id);
        $this->get("/api/v1/ward/handover/{$id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');

        $other = Hospital::factory()->create();
        Sanctum::actingAs($this->staff('nurse', $other));
        app(CurrentHospital::class)->set($other->id);
        $this->getJson('/api/v1/ward/handover/history')->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson("/api/v1/ward/handover/{$id}/acknowledge")->assertStatus(404);
        $this->get("/api/v1/ward/handover/{$id}/pdf")->assertStatus(404);
        $this->postJson('/api/v1/ward/handover', $payload)->assertStatus(404);
    }

    public function test_possible_duplicates_say_why_and_never_cross_hospitals(): void
    {
        $same = Patient::factory()->create(['hospital_id' => $this->h->id, 'first_name' => 'Amina', 'last_name' => 'Nakato', 'dob' => '1990-04-02', 'phone_1' => '+256 700 123 456']);
        $similar = Patient::factory()->create(['hospital_id' => $this->h->id, 'first_name' => 'Aminah', 'last_name' => 'Nakatto', 'dob' => '1985-01-01', 'phone_1' => '0777000111']);
        Patient::factory()->create(['hospital_id' => $this->h->id, 'first_name' => 'Peter', 'last_name' => 'Opio', 'phone_1' => '0788999000']);
        $foreign = Hospital::factory()->create();
        Patient::factory()->create(['hospital_id' => $foreign->id, 'first_name' => 'Amina', 'last_name' => 'Nakato', 'dob' => '1990-04-02', 'phone_1' => '0700123456']);

        $this->getJson('/api/v1/patients/possible-duplicates?first=Amina')->assertStatus(401);
        Sanctum::actingAs($this->staff('pharmacist'));
        $this->getJson('/api/v1/patients/possible-duplicates?first=Amina')->assertOk(); // patients.view
        Sanctum::actingAs(User::factory()->create(['hospital_id' => $this->h->id, 'role' => 'nobody']));
        $this->getJson('/api/v1/patients/possible-duplicates?first=Amina')->assertStatus(403);

        Sanctum::actingAs($this->staff('receptionist'));
        $this->getJson('/api/v1/patients/possible-duplicates')->assertStatus(422);
        $this->getJson('/api/v1/patients/possible-duplicates?first=Amina&dob=02-04-1990')->assertStatus(422);

        // The same phone typed differently is the strongest reason.
        $r = $this->getJson('/api/v1/patients/possible-duplicates?first=Amina&last=Nakato&dob=1990-04-02&phone=0700123456')->assertOk()
            ->assertJsonPath('data.candidates.0.uuid', $same->uuid)
            ->assertJsonPath('data.candidates.0.reason', 'Same phone')
            ->assertJsonPath('data.candidates.0.patient_no', $same->patient_no);
        $uuids = array_column($r->json('data.candidates'), 'uuid');
        $this->assertContains($similar->uuid, $uuids);
        $this->assertCount(2, $uuids, 'the other hospital and the unrelated patient are not candidates');

        $this->getJson('/api/v1/patients/possible-duplicates?first=Amina&last=Nakato&dob=1990-04-02')->assertOk()
            ->assertJsonPath('data.candidates.0.reason', 'Same name and date of birth')
            ->assertJsonPath('data.candidates.1.reason', 'Similar name');
        $this->getJson('/api/v1/patients/possible-duplicates?phone=0700123456')->assertOk()->assertJsonCount(1, 'data.candidates');

        // A real patient is still reachable by uuid at the neighbouring path.
        $this->getJson("/api/v1/patients/{$same->uuid}")->assertOk();
    }
}
