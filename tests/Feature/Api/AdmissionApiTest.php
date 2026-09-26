<?php

namespace Tests\Feature\Api;

use App\Models\Bed;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdmissionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_patient_is_moved_to_a_free_bed_and_then_discharged(): void
    {
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $ward = Ward::factory()->create(['hospital_id' => $h->id, 'name' => 'Female ward']);
        $a = Bed::factory()->create(['hospital_id' => $h->id, 'ward_id' => $ward->id, 'name' => 'F-1']);
        $b = Bed::factory()->create(['hospital_id' => $h->id, 'ward_id' => $ward->id, 'name' => 'F-2']);
        $nurse = User::factory()->create(['hospital_id' => $h->id, 'role' => 'nurse']);
        $nurse->syncSpatieRole();
        $admission = app(AdmissionService::class)->admit(Patient::factory()->create(['hospital_id' => $h->id]), $a, [], $nurse->id);
        Sanctum::actingAs($nurse);

        $beds = collect($this->getJson('/api/v1/beds')->assertOk()->json('data.0.beds'))->keyBy('name');
        $this->assertSame('occupied', $beds['F-1']['status']);

        $this->postJson("/api/v1/admissions/{$admission->uuid}/transfer", ['to_bed_id' => $a->id])->assertStatus(422);
        $this->postJson("/api/v1/admissions/{$admission->uuid}/transfer", ['to_bed_id' => $b->id, 'reason' => 'Nearer the station'])
            ->assertOk()->assertJsonPath('message', 'Patient transferred to F-2.');
        $this->postJson("/api/v1/admissions/{$admission->uuid}/discharge", ['outcome' => 'admitted'])->assertStatus(422);
        $this->postJson("/api/v1/admissions/{$admission->uuid}/discharge", ['outcome' => 'discharged', 'discharge_notes' => 'Well'])->assertOk();

        $this->assertSame('available', $b->fresh()->status->value, 'the bed is freed');
    }
}
