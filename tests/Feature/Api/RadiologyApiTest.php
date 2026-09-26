<?php

namespace Tests\Feature\Api;

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\RadiologyStudy;
use App\Models\User;
use App\Models\Visit;
use App\Services\RadiologyService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RadiologyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_room_works_an_order_through_to_a_signed_report(): void
    {
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $patient = Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Ruth', 'last_name' => 'Kobusingye']);
        $visit = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $patient->id]);
        $study = RadiologyStudy::factory()->create(['hospital_id' => $h->id, 'name' => 'Chest X-ray', 'price' => '40000.00']);
        $order = app(RadiologyService::class)->order($visit->fresh(), [$study->id]);

        $doctor = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor']);
        $doctor->syncSpatieRole();
        Sanctum::actingAs($doctor);
        $this->getJson('/api/v1/radiology-orders')->assertOk()->assertJsonPath('meta.tally.ordered', 1);
        $this->postJson("/api/v1/radiology-orders/{$order->uuid}/transition", ['status' => 'scheduled'])->assertStatus(403);

        $admin = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $admin->syncSpatieRole();
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/radiology-orders?outstanding=1&q=ruth%20kobusingye')->assertJsonPath('data.0.studies.0.name', 'Chest X-ray');
        $this->postJson("/api/v1/radiology-orders/{$order->uuid}/transition", ['status' => 'reported'])->assertStatus(422);
        $this->postJson("/api/v1/radiology-orders/{$order->uuid}/transition", ['status' => 'scheduled'])->assertOk();
        $this->postJson("/api/v1/radiology-orders/{$order->uuid}/transition", ['status' => 'performed'])->assertOk();
        $this->putJson("/api/v1/radiology-orders/{$order->uuid}/report", ['findings' => 'Clear lung fields.', 'impression' => 'Normal study.', 'sign_off' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'reported')
            ->assertJsonPath('data.impression', 'Normal study.')
            ->assertJsonPath('message', 'Reported and signed off.');
    }
}
