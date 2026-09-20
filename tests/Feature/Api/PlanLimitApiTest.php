<?php

namespace Tests\Feature\Api;

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlanLimitApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    public function test_api_patient_create_is_blocked_at_the_plan_limit(): void
    {
        $h = Hospital::factory()->create();
        $plan = Plan::factory()->create(['limits' => ['max_patients' => 1]]);
        Subscription::factory()->create(['hospital_id' => $h->id, 'plan_id' => $plan->id]);
        app(CurrentHospital::class)->set($h->id);
        Patient::factory()->create(['hospital_id' => $h->id]); // fills the single seat

        $recep = User::factory()->create(['hospital_id' => $h->id, 'role' => 'receptionist']);
        $recep->syncSpatieRole();
        Sanctum::actingAs($recep);

        $this->postJson('/api/v1/patients', ['first_name' => 'Over', 'last_name' => 'Limit', 'consent_given' => true])
            ->assertStatus(403)->assertJsonPath('code', 'forbidden');

        $this->assertSame(1, Patient::withoutGlobalScopes()->where('hospital_id', $h->id)->count());
    }

    public function test_without_a_plan_creation_is_unlimited(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $recep = User::factory()->create(['hospital_id' => $h->id, 'role' => 'receptionist']);
        $recep->syncSpatieRole();
        Sanctum::actingAs($recep);

        $this->postJson('/api/v1/patients', ['first_name' => 'Free', 'last_name' => 'ToAdd', 'consent_given' => true])
            ->assertStatus(201);
    }
}
