<?php

namespace Tests\Feature\Api;

use App\Models\Bed;
use App\Models\Hospital;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Models\Ward;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The work on a visit through the API — the same OrderDesk the web panel uses. */
class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    private Visit $visit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->h->id);
        $patient = Patient::factory()->create(['hospital_id' => $this->h->id]);
        $this->visit = Visit::factory()->create(['hospital_id' => $this->h->id, 'patient_id' => $patient->id, 'status' => 'ongoing']);
    }

    private function staff(string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $this->h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    public function test_a_procedure_is_placed_worked_and_finished_with_evidence(): void
    {
        Sanctum::actingAs($this->staff('doctor'));
        $service = Service::factory()->create(['hospital_id' => $this->h->id, 'name' => 'Wound dressing', 'price' => '15000.00']);

        $this->postJson("/api/v1/visits/{$this->visit->uuid}/orders", ['type' => 'procedure'])
            ->assertStatus(422)->assertJsonValidationErrors(['title'], 'errors');
        $this->postJson("/api/v1/visits/{$this->visit->uuid}/orders", ['type' => 'procedure', 'title' => 'Dress the wound'])
            ->assertCreated()->assertJsonPath('message', 'Order placed.');

        $list = $this->getJson("/api/v1/visits/{$this->visit->uuid}/orders")->assertOk();
        $list->assertJsonPath('data.counts.procedure', 1)->assertJsonPath('data.writable', true);
        $uuid = $list->json('data.orders.0.uuid');
        $this->assertSame(['in_progress', 'completed'], $list->json('data.orders.0.next_statuses'));

        // Done means something was done.
        $this->postJson("/api/v1/orders/{$uuid}/move", ['status' => 'completed'])->assertStatus(422);
        $this->postJson("/api/v1/orders/{$uuid}/items", ['kind' => 'service', 'service_id' => $service->id, 'quantity' => 1])
            ->assertCreated()->assertJsonPath('data.total', '15000.00')->assertJsonPath('data.completable', true);
        $this->postJson("/api/v1/orders/{$uuid}/move", ['status' => 'completed'])->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_cancelling_takes_the_charges_off_and_says_what_it_undoes(): void
    {
        Sanctum::actingAs($this->staff('doctor'));
        $service = Service::factory()->create(['hospital_id' => $this->h->id, 'price' => '5000.00']);
        $this->postJson("/api/v1/visits/{$this->visit->uuid}/orders", ['type' => 'consultation', 'title' => 'Review'])->assertCreated();
        $uuid = $this->getJson("/api/v1/visits/{$this->visit->uuid}/orders")->json('data.orders.0.uuid');
        $this->postJson("/api/v1/orders/{$uuid}/items", ['kind' => 'service', 'service_id' => $service->id, 'quantity' => 2])->assertCreated();

        $this->getJson("/api/v1/orders/{$uuid}")->assertOk()->assertJsonPath('data.reversal.total', '10000.00');
        $this->postJson("/api/v1/orders/{$uuid}/move", ['status' => 'cancelled'])->assertStatus(422);
        $this->postJson("/api/v1/orders/{$uuid}/cancel", ['reason' => 'Raised on the wrong patient'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel_reason', 'Raised on the wrong patient');
    }

    public function test_lab_tests_need_the_lab_permission_and_a_test(): void
    {
        $test = LabTest::factory()->create(['hospital_id' => $this->h->id, 'name' => 'Haemoglobin', 'price' => '8000.00']);

        Sanctum::actingAs($this->staff('receptionist'));
        $this->postJson("/api/v1/visits/{$this->visit->uuid}/orders", ['type' => 'lab', 'test_ids' => [$test->id]])->assertStatus(403);

        Sanctum::actingAs($this->staff('doctor'));
        $this->getJson('/api/v1/orders/catalogue?type=lab&q=haemo')->assertOk()->assertJsonPath('data.rows.0.name', 'Haemoglobin');
        $this->postJson("/api/v1/visits/{$this->visit->uuid}/orders", ['type' => 'lab', 'test_ids' => []])
            ->assertStatus(422)->assertJsonValidationErrors(['test_ids'], 'errors');
        $this->postJson("/api/v1/visits/{$this->visit->uuid}/orders", ['type' => 'lab', 'test_ids' => [$test->id]])
            ->assertCreated()->assertJsonPath('message', 'Lab tests ordered.');
    }

    public function test_admitting_claims_a_bed_and_finishing_the_stay_discharges(): void
    {
        $ward = Ward::factory()->create(['hospital_id' => $this->h->id]);
        $bed = Bed::factory()->create(['hospital_id' => $this->h->id, 'ward_id' => $ward->id]);
        Sanctum::actingAs($this->staff('doctor'));

        $this->postJson("/api/v1/visits/{$this->visit->uuid}/orders", ['type' => 'admission', 'title' => 'Admit for observation'])
            ->assertStatus(422)->assertJsonPath('errors.bed_id.0', 'Choose a bed for the patient.');
        $this->postJson("/api/v1/visits/{$this->visit->uuid}/orders", ['type' => 'admission', 'title' => 'Admit for observation', 'bed_id' => $bed->id])
            ->assertCreated()->assertJsonPath('message', 'Patient admitted.');

        $order = $this->getJson("/api/v1/visits/{$this->visit->uuid}/orders")->json('data.orders.0');
        $this->assertSame('admitted', $order['stay']['status']);

        $this->postJson("/api/v1/orders/{$order['uuid']}/move", ['status' => 'completed'])
            ->assertOk()->assertJsonPath('message', 'Patient discharged and the bed freed.');
    }

    public function test_nothing_is_added_to_a_finished_visit(): void
    {
        $this->visit->forceFill(['status' => 'completed', 'outcome' => 'cancelled'])->save();
        Sanctum::actingAs($this->staff('doctor'));

        $this->postJson("/api/v1/visits/{$this->visit->uuid}/orders", ['type' => 'procedure', 'title' => 'Late work'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'reopen it'));
        $this->getJson("/api/v1/visits/{$this->visit->uuid}/orders")->assertJsonPath('data.writable', false);
    }

    public function test_a_nurse_may_read_but_not_write(): void
    {
        Sanctum::actingAs($this->staff('nurse'));
        $this->getJson("/api/v1/visits/{$this->visit->uuid}/orders")->assertOk()->assertJsonPath('data.writable', false);
        $this->postJson("/api/v1/visits/{$this->visit->uuid}/orders", ['type' => 'procedure', 'title' => 'x'])->assertStatus(403);
    }

    /** A prescription schedules its doses; a nurse gives one; a nurse cannot prescribe. */
    public function test_a_prescription_schedules_doses_a_nurse_then_gives(): void
    {
        Sanctum::actingAs($this->staff('nurse'));
        $this->postJson("/api/v1/visits/{$this->visit->uuid}/prescriptions", ['items' => [['drug_name' => 'Amoxicillin', 'slots' => ['morning'], 'days' => 1]]])
            ->assertStatus(403);

        Sanctum::actingAs($this->staff('doctor'));
        $this->postJson("/api/v1/visits/{$this->visit->uuid}/prescriptions", ['items' => [['drug_name' => 'Amoxicillin', 'slots' => ['breakfast'], 'days' => 0]]])
            ->assertStatus(422)->assertJsonValidationErrors(['items.0.slots.0', 'items.0.days'], 'errors');
        $this->postJson("/api/v1/visits/{$this->visit->uuid}/prescriptions", [
            'items' => [['drug_name' => 'Amoxicillin 500mg', 'dosage' => '1 cap', 'slots' => ['morning', 'night'], 'days' => 2]],
        ])->assertCreated();

        $rx = $this->getJson("/api/v1/visits/{$this->visit->uuid}/prescriptions")->assertOk()->json('data.0.items.0');
        $this->assertCount(4, $rx['doses'], 'two a day for two days');
        $this->assertSame('pending', $rx['doses'][0]['status']);

        Sanctum::actingAs($this->staff('nurse'));
        $this->postJson("/api/v1/doses/{$rx['doses'][0]['id']}", ['status' => 'administered'])
            ->assertOk()->assertJsonPath('data.status', 'administered');
        $this->postJson("/api/v1/doses/{$rx['doses'][1]['id']}", ['status' => 'pending'])->assertStatus(422);
    }
}
