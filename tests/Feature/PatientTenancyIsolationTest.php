<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HMS_PLAN.md §2.1 — patients ship with an isolation test proving one hospital
 * can never read or write another hospital's patient rows (UI paths here).
 */
class PatientTenancyIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function admin(Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $u->syncSpatieRole();

        return $u;
    }

    public function test_hospital_a_cannot_list_hospital_bs_patients(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        Patient::factory()->create(['hospital_id' => $b->id, 'first_name' => 'Zeb', 'last_name' => 'FromB']);

        $res = $this->actingAs($this->admin($a))->get('/admin/patients');

        $res->assertOk();
        $res->assertDontSee('FromB');
    }

    public function test_hospital_a_cannot_view_or_edit_hospital_bs_patient(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $patientB = Patient::factory()->create(['hospital_id' => $b->id, 'first_name' => 'Secret']);
        $adminA = $this->admin($a);

        $this->actingAs($adminA)->get("/admin/patients/{$patientB->uuid}")->assertNotFound();
        $this->actingAs($adminA)->get("/admin/patients/{$patientB->uuid}/edit")->assertNotFound();

        // The editor is Livewire now: route-model binding refuses to hydrate
        // B's patient for A, so there is no write surface to tamper with.
        app(CurrentHospital::class)->set($a->id);
        $this->assertNull(Patient::where('uuid', $patientB->uuid)->first());

        $this->assertSame('Secret', Patient::withoutGlobalScopes()->find($patientB->id)->first_name);
    }

    public function test_super_admin_sees_patients_across_hospitals(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        Patient::factory()->create(['hospital_id' => $a->id, 'first_name' => 'Alpha', 'last_name' => 'PatA']);
        Patient::factory()->create(['hospital_id' => $b->id, 'first_name' => 'Beta', 'last_name' => 'PatB']);

        $super = User::factory()->create(['hospital_id' => null, 'role' => 'super_admin', 'is_admin' => true]);
        $super->syncSpatieRole();

        $res = $this->actingAs($super)->get('/admin/patients');
        $res->assertOk();
        $res->assertSee('PatA');
        $res->assertSee('PatB');
    }
}
