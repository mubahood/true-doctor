<?php

namespace Tests\Feature\Api;

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PatientApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function staff(Hospital $h, string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role, 'password' => Hash::make('secret123')]);
        $u->syncSpatieRole();

        return $u;
    }

    public function test_index_is_scoped_to_the_token_users_hospital(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        Patient::factory()->create(['hospital_id' => $a->id, 'first_name' => 'Alpha', 'last_name' => 'InA']);
        Patient::factory()->create(['hospital_id' => $b->id, 'first_name' => 'Beta', 'last_name' => 'InB']);

        Sanctum::actingAs($this->staff($a, 'receptionist'));
        $res = $this->getJson('/api/v1/patients');

        $res->assertOk()->assertJsonPath('success', true)->assertJsonStructure(['data', 'meta' => ['total', 'current_page']]);
        $this->assertSame(1, $res->json('meta.total'));
        $this->assertSame('Alpha', $res->json('data.0.first_name'));
    }

    public function test_create_via_api_registers_a_patient(): void
    {
        $h = Hospital::factory()->create();
        Sanctum::actingAs($this->staff($h, 'receptionist'));

        $res = $this->postJson('/api/v1/patients', ['first_name' => 'New', 'last_name' => 'ApiPatient', 'consent_given' => true]);

        $res->assertStatus(201)->assertJsonPath('data.first_name', 'New');
        $this->assertNotEmpty($res->json('data.patient_no'));
        $this->assertDatabaseHas('patients', ['hospital_id' => $h->id, 'first_name' => 'New']);
    }

    public function test_rbac_is_mirrored_a_role_without_create_is_forbidden(): void
    {
        $h = Hospital::factory()->create();
        Sanctum::actingAs($this->staff($h, 'pharmacist'));

        $this->postJson('/api/v1/patients', ['first_name' => 'X', 'last_name' => 'Y', 'consent_given' => true])
            ->assertStatus(403)->assertJsonPath('code', 'forbidden');
    }

    public function test_cannot_read_another_hospitals_patient(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $patientB = Patient::factory()->create(['hospital_id' => $b->id]);

        Sanctum::actingAs($this->staff($a, 'hospital_admin'));
        $this->getJson("/api/v1/patients/{$patientB->uuid}")->assertStatus(404)->assertJsonPath('code', 'not_found');
    }

    public function test_validation_errors_use_the_envelope(): void
    {
        $h = Hospital::factory()->create();
        Sanctum::actingAs($this->staff($h, 'receptionist'));

        $this->postJson('/api/v1/patients', ['first_name' => ''])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors']);
    }
}
