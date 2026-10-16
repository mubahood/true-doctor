<?php

namespace Tests\Feature\Api;

use App\Enums\BedStatus;
use App\Models\Bed;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\Plan;
use App\Models\Room;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Departments, rooms, wards and beds over the API: the web pages' rules, permissions and tenancy. */
class AdminStructureApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    private Hospital $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->h = Hospital::factory()->create();
        $this->other = Hospital::factory()->create();
    }

    private function as(string $role, ?Hospital $h = null): User
    {
        $u = User::factory()->create(['hospital_id' => ($h ?? $this->h)->id, 'role' => $role]);
        $u->syncSpatieRole();
        Sanctum::actingAs($u);

        return $u;
    }

    // ── Departments ─────────────────────────────────────────────

    public function test_admin_manages_departments_and_the_code_is_upper_cased(): void
    {
        $this->as('hospital_admin');

        $id = $this->postJson('/api/v1/admin/departments', ['name' => 'Cardiology', 'code' => 'card'])
            ->assertCreated()->assertJsonPath('data.code', 'CARD')->assertJsonPath('data.is_active', true)->json('data.id');

        $this->postJson('/api/v1/admin/departments', ['name' => 'Cardiology'])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('name', 'errors');

        // A partial update changes what was sent and nothing else.
        $this->putJson("/api/v1/admin/departments/{$id}", ['description' => 'Hearts'])
            ->assertOk()->assertJsonPath('data.description', 'Hearts')->assertJsonPath('data.is_active', true)->assertJsonPath('data.code', 'CARD');

        $this->getJson('/api/v1/admin/departments?q=card')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/departments?status=inactive')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/admin/departments/{$id}")->assertOk()->assertJsonPath('data.name', 'Cardiology');

        $this->deleteJson("/api/v1/admin/departments/{$id}")->assertOk();
        // Archived, so the same name comes back by restoring it.
        $this->postJson('/api/v1/admin/departments', ['name' => 'Cardiology', 'code' => 'CARD'])
            ->assertCreated()->assertJsonPath('data.id', $id);
    }

    public function test_a_receptionist_can_view_departments_but_not_change_them(): void
    {
        $dep = Department::factory()->create(['hospital_id' => $this->h->id]);
        $this->as('receptionist');

        $this->getJson('/api/v1/admin/departments')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson('/api/v1/admin/departments', ['name' => 'X'])->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->putJson("/api/v1/admin/departments/{$dep->id}", ['name' => 'Y'])->assertStatus(403);
        $this->deleteJson("/api/v1/admin/departments/{$dep->id}")->assertStatus(403);
    }

    public function test_departments_are_walled_off_between_hospitals(): void
    {
        $theirs = Department::factory()->create(['hospital_id' => $this->other->id, 'name' => 'Theirs']);
        $foreignUser = User::factory()->create(['hospital_id' => $this->other->id, 'role' => 'doctor']);
        $this->as('hospital_admin');

        $this->getJson('/api/v1/admin/departments')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/admin/departments/{$theirs->id}")->assertStatus(404);
        $this->putJson("/api/v1/admin/departments/{$theirs->id}", ['name' => 'Mine now'])->assertStatus(404);
        $this->deleteJson("/api/v1/admin/departments/{$theirs->id}")->assertStatus(404);
        // Their staff cannot be made the head here, and the name is free to reuse.
        $this->postJson('/api/v1/admin/departments', ['name' => 'Heads', 'head_user_id' => $foreignUser->id])->assertStatus(422);
        $this->postJson('/api/v1/admin/departments', ['name' => 'Theirs'])->assertCreated();
    }

    // ── Rooms ───────────────────────────────────────────────────

    public function test_rooms_validate_their_enums_and_filter(): void
    {
        $this->as('hospital_admin');
        $dep = Department::factory()->create(['hospital_id' => $this->h->id]);

        $this->postJson('/api/v1/admin/rooms', ['name' => 'R1', 'type' => 'nonsense', 'status' => 'available'])
            ->assertStatus(422)->assertJsonValidationErrors('type', 'errors');

        $id = $this->postJson('/api/v1/admin/rooms', ['name' => 'R1', 'type' => 'consultation', 'status' => 'available', 'department_id' => $dep->id])
            ->assertCreated()->assertJsonPath('data.capacity', 1)->assertJsonPath('data.type_label', 'Consultation')->assertJsonPath('data.is_bookable', true)
            ->json('data.id');

        $this->putJson("/api/v1/admin/rooms/{$id}", ['status' => 'maintenance'])->assertOk()->assertJsonPath('data.is_bookable', false)->assertJsonPath('data.type', 'consultation');
        $this->getJson('/api/v1/admin/rooms?status=maintenance')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/admin/rooms?department_id={$dep->id}&q=R1")->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/rooms/options')->assertOk()->assertJsonStructure(['data' => ['types', 'statuses', 'departments']]);

        $foreignDep = Department::factory()->create(['hospital_id' => $this->other->id]);
        $this->postJson('/api/v1/admin/rooms', ['name' => 'R2', 'type' => 'ward', 'status' => 'available', 'department_id' => $foreignDep->id])->assertStatus(422);

        $theirs = Room::factory()->create(['hospital_id' => $this->other->id]);
        $this->getJson("/api/v1/admin/rooms/{$theirs->id}")->assertStatus(404);
        $this->deleteJson("/api/v1/admin/rooms/{$id}")->assertOk();
    }

    public function test_rooms_need_rooms_manage_to_change(): void
    {
        $this->as('nurse');
        $this->getJson('/api/v1/admin/rooms')->assertOk();
        $this->postJson('/api/v1/admin/rooms', ['name' => 'R1', 'type' => 'ward', 'status' => 'available'])->assertStatus(403);
    }

    // ── Wards and beds ──────────────────────────────────────────

    public function test_wards_and_beds_need_ipd_view_to_be_seen_and_wards_manage_to_change(): void
    {
        $this->as('receptionist');
        $this->getJson('/api/v1/admin/wards')->assertStatus(403);
        $this->getJson('/api/v1/admin/beds')->assertStatus(403);

        $this->as('nurse');   // ipd.view, not wards.manage
        $this->getJson('/api/v1/admin/wards')->assertOk();
        $this->getJson('/api/v1/admin/beds')->assertOk();
        $this->postJson('/api/v1/admin/wards', ['name' => 'W', 'default_daily_charge' => '10'])->assertStatus(403);
        $this->postJson('/api/v1/admin/beds', ['ward_id' => 1, 'name' => 'B1', 'daily_charge' => '10', 'status' => 'available'])->assertStatus(403);
    }

    public function test_a_ward_reprices_its_beds_only_when_asked_and_cannot_be_removed_with_beds(): void
    {
        $this->as('hospital_admin');
        $wardId = $this->postJson('/api/v1/admin/wards', ['name' => 'Ward A', 'default_daily_charge' => '40000'])
            ->assertCreated()->assertJsonPath('data.default_daily_charge', '40000.00')->json('data.id');

        // A new bed starts at the ward's standard rate.
        $bedId = $this->postJson('/api/v1/admin/beds', ['ward_id' => $wardId, 'name' => 'B1'])
            ->assertCreated()->assertJsonPath('data.daily_charge', '40000.00')->assertJsonPath('data.status', 'available')->json('data.id');

        // Changing the ward rate alone leaves the bed's price alone.
        $this->putJson("/api/v1/admin/wards/{$wardId}", ['default_daily_charge' => '50000'])->assertOk()->assertJsonPath('data.repriced', null);
        $this->assertSame('40000.00', (string) Bed::find($bedId)->daily_charge);

        $this->getJson("/api/v1/admin/wards/{$wardId}/reprice-impact")->assertOk()->assertJsonPath('data.beds', 1);
        $this->putJson("/api/v1/admin/wards/{$wardId}", ['apply_to_beds' => true])->assertOk()->assertJsonPath('data.repriced.beds', 1);
        $this->assertSame('50000.00', (string) Bed::find($bedId)->daily_charge);

        $this->getJson("/api/v1/admin/wards/{$wardId}")->assertOk()->assertJsonPath('data.figures.beds', 1)->assertJsonPath('data.figures.free', 1);
        $this->deleteJson("/api/v1/admin/wards/{$wardId}")->assertStatus(422)->assertJsonPath('message', 'This ward has beds — remove them first.');

        $this->deleteJson("/api/v1/admin/beds/{$bedId}")->assertOk();
        $this->deleteJson("/api/v1/admin/wards/{$wardId}")->assertOk();
    }

    public function test_bed_status_changes_but_never_to_or_from_occupied(): void
    {
        $this->as('hospital_admin');
        $ward = Ward::factory()->create(['hospital_id' => $this->h->id]);
        $bed = Bed::factory()->create(['hospital_id' => $this->h->id, 'ward_id' => $ward->id]);

        $this->postJson("/api/v1/admin/beds/{$bed->id}/status", ['status' => 'maintenance'])->assertOk()->assertJsonPath('data.status', 'maintenance');
        $this->postJson("/api/v1/admin/beds/{$bed->id}/status", ['status' => 'available'])->assertOk()->assertJsonPath('data.status', 'available');
        $this->postJson("/api/v1/admin/beds/{$bed->id}/status", ['status' => 'occupied'])->assertStatus(422);
        $this->postJson("/api/v1/admin/beds/{$bed->id}/status", ['status' => 'bogus'])->assertStatus(422);

        $bed->forceFill(['status' => BedStatus::Occupied])->save();
        $this->postJson("/api/v1/admin/beds/{$bed->id}/status", ['status' => 'maintenance'])->assertStatus(422);
        // An edit cannot flip it by hand either, and it cannot be deleted.
        $this->putJson("/api/v1/admin/beds/{$bed->id}", ['status' => 'available', 'name' => 'Renamed'])->assertOk()->assertJsonPath('data.status', 'occupied')->assertJsonPath('data.name', 'Renamed');
        $this->deleteJson("/api/v1/admin/beds/{$bed->id}")->assertStatus(422)->assertJsonPath('message', 'An occupied bed cannot be deleted.');
    }

    public function test_the_plan_bed_limit_is_enforced_as_on_the_web(): void
    {
        $plan = Plan::factory()->create(['limits' => ['max_beds' => 1]]);
        Subscription::factory()->create(['hospital_id' => $this->h->id, 'plan_id' => $plan->id]);
        $ward = Ward::factory()->create(['hospital_id' => $this->h->id]);
        $this->as('hospital_admin');

        $this->postJson('/api/v1/admin/beds', ['ward_id' => $ward->id, 'name' => 'B1', 'daily_charge' => '10', 'status' => 'available'])->assertCreated();
        $this->postJson('/api/v1/admin/beds', ['ward_id' => $ward->id, 'name' => 'B2', 'daily_charge' => '10', 'status' => 'available'])
            ->assertStatus(403)->assertJsonPath('code', 'plan_limit');
        $this->assertSame(1, Bed::withoutGlobalScopes()->where('hospital_id', $this->h->id)->count());
    }

    public function test_wards_and_beds_are_walled_off_between_hospitals(): void
    {
        $theirWard = Ward::factory()->create(['hospital_id' => $this->other->id]);
        $theirBed = Bed::factory()->create(['hospital_id' => $this->other->id, 'ward_id' => $theirWard->id]);
        $this->as('hospital_admin');

        $this->getJson('/api/v1/admin/wards')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/admin/beds')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/admin/wards/{$theirWard->id}")->assertStatus(404);
        $this->getJson("/api/v1/admin/beds/{$theirBed->id}")->assertStatus(404);
        $this->postJson("/api/v1/admin/beds/{$theirBed->id}/status", ['status' => 'maintenance'])->assertStatus(404);
        $this->deleteJson("/api/v1/admin/beds/{$theirBed->id}")->assertStatus(404);
        // Nor can a bed be put into their ward.
        $this->postJson('/api/v1/admin/beds', ['ward_id' => $theirWard->id, 'name' => 'B', 'daily_charge' => '1', 'status' => 'available'])->assertStatus(422);
    }
}
