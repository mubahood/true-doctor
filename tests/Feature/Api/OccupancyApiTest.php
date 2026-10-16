<?php

namespace Tests\Feature\Api;

use App\Enums\AdmissionStatus;
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

class OccupancyApiTest extends TestCase
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

    private function user(string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $this->h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function bed(string $name, ?Ward $ward = null): Bed
    {
        $ward ??= $this->ward;

        return Bed::factory()->create(['hospital_id' => $this->h->id, 'ward_id' => $ward->id, 'name' => $name, 'daily_charge' => '40.00']);
    }

    private function fill(Bed $bed, string $first): \App\Models\Admission
    {
        $p = Patient::factory()->create(['hospital_id' => $this->h->id, 'first_name' => $first]);

        return app(AdmissionService::class)->admit($p, $bed, ['visit_id' => Visit::factory()->create(['hospital_id' => $this->h->id, 'patient_id' => $p->id])->id, 'reason' => 'Fever'], null);
    }

    public function test_the_board_gives_wards_beds_occupants_and_hospital_wide_figures(): void
    {
        $a = $this->bed('M-1');
        $this->bed('M-2');
        $maint = $this->bed('M-3');
        $maint->update(['status' => 'maintenance']);
        $adm = $this->fill($a, 'Amina');
        $this->bed('F-1', Ward::factory()->create(['hospital_id' => $this->h->id, 'name' => 'Female ward']));
        Sanctum::actingAs($this->user('nurse'));

        $res = $this->getJson('/api/v1/occupancy')->assertOk();
        $res->assertJsonPath('data.figures', ['total' => 4, 'occupied' => 1, 'free' => 2, 'maintenance' => 1, 'percent' => 25]);
        $this->assertCount(2, $res->json('data.wards'));
        $bed = collect($res->json('data.wards.1.beds'))->firstWhere('name', 'M-1');
        $this->assertSame('Male ward', $res->json('data.wards.1.name'));
        $this->assertSame($adm->uuid, $bed['occupant']['admission_uuid']);
        $this->assertSame($adm->patient->full_name, $bed['occupant']['patient']['name']);
        $this->assertSame('40.00', $bed['occupant']['accrued_so_far']);
        $this->assertNull(collect($res->json('data.wards.1.beds'))->firstWhere('name', 'M-2')['occupant']);

        // Filters narrow the beds but never the figures.
        $this->getJson('/api/v1/occupancy?status=available')->assertJsonCount(2, 'data.wards')->assertJsonPath('data.figures.total', 4);
        $this->getJson('/api/v1/occupancy?q=Amina')->assertJsonCount(1, 'data.wards')->assertJsonCount(1, 'data.wards.0.beds');
        $this->getJson('/api/v1/occupancy?ward='.$this->ward->id)->assertJsonCount(1, 'data.wards');
        $this->getJson('/api/v1/occupancy?status=bogus')->assertStatus(422);
    }

    public function test_the_existing_beds_endpoint_now_names_the_occupant_and_stays_compatible(): void
    {
        $a = $this->bed('M-1');
        $adm = $this->fill($a, 'Amina');
        Sanctum::actingAs($this->user('nurse'));

        $beds = collect($this->getJson('/api/v1/beds')->assertOk()->json('data.0.beds'))->keyBy('name');
        $this->assertSame('occupied', $beds['M-1']['status']);
        $this->assertSame('40.00', $beds['M-1']['daily_charge']);
        $this->assertSame($adm->uuid, $beds['M-1']['occupant']['admission_uuid']);
    }

    public function test_one_bed_shows_the_stay_or_who_left_last(): void
    {
        $a = $this->bed('M-1');
        $adm = $this->fill($a, 'Amina');
        Sanctum::actingAs($this->user('nurse'));

        $this->getJson("/api/v1/occupancy/beds/{$a->id}")->assertOk()
            ->assertJsonPath('data.occupant.reason', 'Fever')->assertJsonPath('data.last_stay', null)->assertJsonPath('data.ward.name', 'Male ward');

        app(AdmissionService::class)->discharge($adm, AdmissionStatus::Discharged, null, null);
        $this->getJson("/api/v1/occupancy/beds/{$a->id}")->assertOk()
            ->assertJsonPath('data.occupant', null)->assertJsonPath('data.last_stay.patient_name', $adm->patient->full_name);
    }

    public function test_bed_status_is_set_by_ward_managers_only_and_never_to_occupied(): void
    {
        $free = $this->bed('M-1');
        $taken = $this->bed('M-2');
        $this->fill($taken, 'Amina');

        Sanctum::actingAs($this->user('nurse'));
        $this->postJson("/api/v1/occupancy/beds/{$free->id}/status", ['status' => 'maintenance'])->assertForbidden();

        Sanctum::actingAs($this->user('hospital_admin'));
        $this->postJson("/api/v1/occupancy/beds/{$free->id}/status", ['status' => 'maintenance'])->assertOk()->assertJsonPath('message', 'M-1 is now maintenance.');
        $this->assertSame('maintenance', $free->fresh()->status->value);
        $this->postJson("/api/v1/occupancy/beds/{$free->id}/status", ['status' => 'occupied'])->assertStatus(422);
        $this->postJson("/api/v1/occupancy/beds/{$taken->id}/status", ['status' => 'maintenance'])->assertStatus(422)->assertJsonPath('message', 'Discharge or transfer the patient first.');
        $this->postJson("/api/v1/occupancy/beds/{$free->id}/status", ['status' => 'available'])->assertOk();
    }

    public function test_role_denial_and_hospital_isolation(): void
    {
        $mine = $this->bed('M-1');
        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $oWard = Ward::factory()->create(['hospital_id' => $other->id, 'name' => 'Other ward']);
        $oBed = Bed::factory()->create(['hospital_id' => $other->id, 'ward_id' => $oWard->id]);
        app(CurrentHospital::class)->set($this->h->id);

        Sanctum::actingAs($this->user('receptionist'));
        $this->getJson('/api/v1/occupancy')->assertForbidden();
        $this->getJson("/api/v1/occupancy/beds/{$mine->id}")->assertForbidden();

        Sanctum::actingAs($this->user('hospital_admin'));
        $res = $this->getJson('/api/v1/occupancy')->assertOk();
        $this->assertSame(['Male ward'], array_column($res->json('data.wards'), 'name'));
        $res->assertJsonPath('data.figures.total', 1);
        $this->getJson("/api/v1/occupancy/beds/{$oBed->id}")->assertNotFound();
        $this->postJson("/api/v1/occupancy/beds/{$oBed->id}/status", ['status' => 'maintenance'])->assertNotFound();
        $this->assertSame('available', $oBed->fresh()->status->value);
    }
}
