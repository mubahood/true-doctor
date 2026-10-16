<?php

namespace Tests\Feature\Api;

use App\Models\Bed;
use App\Models\Department;
use App\Models\DoctorSchedule;
use App\Models\Hospital;
use App\Models\InsuranceProvider;
use App\Models\LabTest;
use App\Models\Service;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Ward;
use App\Support\CurrentHospital;
use App\Support\ReferenceRevision;
use App\Support\SyncRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** GET /api/v1/bootstrap and the reference revisions behind it (docs/api/plane.md). */
class BootstrapApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);
    }

    private function staff(string $role, ?Hospital $h = null): User
    {
        $u = User::factory()->create(['hospital_id' => ($h ?? $this->hospital)->id, 'role' => $role, 'is_active' => true]);
        $u->syncSpatieRole();

        return $u;
    }

    public function test_it_needs_a_token(): void
    {
        $this->getJson('/api/v1/bootstrap')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    }

    public function test_a_first_launch_returns_everything_the_user_may_see(): void
    {
        $ward = Ward::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Ward A']);
        Bed::factory()->create(['hospital_id' => $this->hospital->id, 'ward_id' => $ward->id, 'name' => 'Bed 1']);
        Service::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Consultation']);
        LabTest::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Malaria RDT']);
        StockItem::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Paracetamol']);
        Department::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Outpatient']);
        InsuranceProvider::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Jubilee']);
        $doctor = $this->staff('doctor');
        DoctorSchedule::factory()->create(['hospital_id' => $this->hospital->id, 'user_id' => $doctor->id]);

        Sanctum::actingAs($this->staff('hospital_admin'));
        $res = $this->getJson('/api/v1/bootstrap')->assertOk();

        $data = $res->json('data');
        $this->assertSame(SyncRevision::current(), $data['revision']);
        $this->assertFalse($data['unchanged']);
        $this->assertSame($data['meta']['capabilities'], $data['capabilities']);
        $this->assertSame('Bed 1', $data['reference']['beds'][0]['name']);
        $this->assertSame('Ward A', $data['reference']['wards'][0]['name']);
        $this->assertSame('Consultation', $data['reference']['services'][0]['name']);
        $this->assertSame('Malaria RDT', $data['reference']['lab_tests'][0]['name']);
        $this->assertSame('Paracetamol', $data['reference']['medications'][0]['name']);
        $this->assertSame('Outpatient', $data['reference']['departments'][0]['name']);
        $this->assertSame('Jubilee', $data['reference']['insurance_providers'][0]['name']);
        $this->assertNotEmpty($data['reference']['payment_methods']);
        $this->assertContains('cash', array_column($data['reference']['payment_methods'], 'value'));

        $doctors = collect($data['reference']['doctors']);
        $this->assertTrue($doctors->contains('id', $doctor->id));
        $this->assertNotEmpty($doctors->firstWhere('id', $doctor->id)['schedules']);

        // Every reference row carries an integer id: an installed app files rows by it.
        foreach ($data['reference'] as $kind => $rows) {
            foreach ($rows as $row) {
                $this->assertIsInt($row['id'], $kind);
            }
        }
        $this->assertSame([], $data['omitted_kinds']);
        $this->assertSame(array_keys($data['reference']), array_keys((array) $data['revisions']));
    }

    public function test_sections_a_user_may_not_see_are_omitted_and_named(): void
    {
        StockItem::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Paracetamol']);
        LabTest::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Malaria RDT']);

        Sanctum::actingAs($this->staff('receptionist'));
        $data = $this->getJson('/api/v1/bootstrap')->assertOk()->json('data');

        $this->assertArrayNotHasKey('medications', $data['reference']);
        $this->assertArrayNotHasKey('lab_tests', $data['reference']);
        $this->assertArrayNotHasKey('medications', (array) $data['revisions']);
        $this->assertContains('medications', $data['omitted_kinds']);
        $this->assertContains('lab_tests', $data['omitted_kinds']);
        $this->assertContains('doctors', array_keys($data['reference']));
    }

    public function test_since_returns_only_the_sections_that_moved_and_unchanged_when_none_did(): void
    {
        Service::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Consultation']);
        $ward = Ward::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Ward A']);
        Sanctum::actingAs($this->staff('hospital_admin'));

        $first = $this->getJson('/api/v1/bootstrap')->json('data');

        $quiet = $this->getJson('/api/v1/bootstrap?since='.$first['revision'])->assertOk()->json('data');
        $this->assertTrue($quiet['unchanged']);
        $this->assertSame([], (array) $quiet['reference']);

        $ward->update(['name' => 'Ward B']);
        $moved = $this->getJson('/api/v1/bootstrap?since='.$first['revision'])->assertOk()->json('data');

        $this->assertFalse($moved['unchanged']);
        $this->assertSame(['wards'], array_keys($moved['reference']));
        $this->assertSame('Ward B', $moved['reference']['wards'][0]['name']);
        $this->assertGreaterThan($first['revision'], $moved['revision']);
    }

    public function test_a_stock_balance_change_does_not_move_the_medications_section(): void
    {
        $item = StockItem::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Paracetamol', 'current_quantity' => '10']);
        Sanctum::actingAs($this->staff('hospital_admin'));
        $before = $this->getJson('/api/v1/bootstrap')->json('data.revisions.medications');

        $item->update(['current_quantity' => '3']);
        $this->assertSame($before, $this->getJson('/api/v1/bootstrap')->json('data.revisions.medications'));

        $item->update(['name' => 'Paracetamol 500']);
        $this->assertGreaterThan($before, $this->getJson('/api/v1/bootstrap')->json('data.revisions.medications'));
    }

    public function test_a_deleted_row_leaves_its_section_and_is_named_in_removed_reference(): void
    {
        $service = Service::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Old service']);
        Service::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Kept']);
        Sanctum::actingAs($this->staff('hospital_admin'));
        $since = $this->getJson('/api/v1/bootstrap')->json('data.revision');

        $service->delete();
        $data = $this->getJson('/api/v1/bootstrap?since='.$since)->json('data');

        $this->assertSame(['Kept'], array_column($data['reference']['services'], 'name'));
        $this->assertSame([$service->id], $data['removed_reference']['services']);
    }

    public function test_have_forces_a_section_the_app_never_held(): void
    {
        Ward::factory()->create(['hospital_id' => $this->hospital->id]);
        Sanctum::actingAs($this->staff('hospital_admin'));
        $rev = $this->getJson('/api/v1/bootstrap')->json('data.revision');

        $data = $this->getJson('/api/v1/bootstrap?since='.$rev.'&have=beds')->json('data');

        $this->assertContains('wards', array_keys($data['reference']));
        $this->assertNotContains('beds', array_keys($data['reference']));
    }

    public function test_reference_is_scoped_to_the_hospital(): void
    {
        Ward::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Mine']);
        $other = Hospital::factory()->create();
        Ward::factory()->create(['hospital_id' => $other->id, 'name' => 'Theirs']);
        Service::factory()->create(['hospital_id' => $other->id, 'name' => 'Their service']);
        $theirDoctor = $this->staff('doctor', $other);

        Sanctum::actingAs($this->staff('hospital_admin'));
        $data = $this->getJson('/api/v1/bootstrap')->json('data');

        $this->assertSame(['Mine'], array_column($data['reference']['wards'], 'name'));
        $this->assertNotContains('Their service', array_column($data['reference']['services'], 'name'));
        $this->assertNotContains($theirDoctor->id, array_column($data['reference']['doctors'], 'id'));
    }

    public function test_another_hospitals_edit_does_not_move_my_revision(): void
    {
        Ward::factory()->create(['hospital_id' => $this->hospital->id]);
        Sanctum::actingAs($this->staff('hospital_admin'));
        $mine = $this->getJson('/api/v1/bootstrap')->json('data.revisions.wards');

        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        Ward::factory()->create(['hospital_id' => $other->id]);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->assertSame($mine, $this->getJson('/api/v1/bootstrap')->json('data.revisions.wards'));
    }

    public function test_the_whole_answer_is_conditional(): void
    {
        Ward::factory()->create(['hospital_id' => $this->hospital->id]);
        Sanctum::actingAs($this->staff('hospital_admin'));

        $first = $this->getJson('/api/v1/bootstrap?since=0');
        $etag = $first->headers->get('ETag');

        $this->withHeader('If-None-Match', $etag)->getJson('/api/v1/bootstrap?since=0')->assertStatus(304);

        Ward::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'New ward']);
        $this->withHeader('If-None-Match', $etag)->getJson('/api/v1/bootstrap?since=0')->assertOk();
    }

    public function test_losing_the_cache_reads_as_changed_once_and_never_hides_a_change(): void
    {
        $ward = Ward::factory()->create(['hospital_id' => $this->hospital->id]);
        Sanctum::actingAs($this->staff('hospital_admin'));
        $rev = $this->getJson('/api/v1/bootstrap')->json('data.revision');

        Cache::flush();

        // A cleared cache falls back to the hospital counter (>= anything issued).
        $afterFlush = ReferenceRevision::of('wards', $this->hospital->id);
        $this->assertGreaterThanOrEqual($rev, $afterFlush);
        // ...and is then stable.
        $this->assertSame($afterFlush, ReferenceRevision::of('wards', $this->hospital->id));

        $ward->update(['name' => 'Renamed']);
        $this->assertGreaterThan($afterFlush, ReferenceRevision::of('wards', $this->hospital->id));
    }

    public function test_sync_reference_honours_since_and_reports_omitted_kinds(): void
    {
        Ward::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Ward A']);
        $nurse = $this->staff('nurse');
        $device = \App\Models\Device::create([
            'hospital_id' => $this->hospital->id, 'user_id' => $nurse->id,
            'device_uuid' => (string) \Illuminate\Support\Str::uuid(), 'label' => 'tab', 'registered_at' => now(),
        ]);
        Sanctum::actingAs($nurse);

        $full = $this->withHeader('X-Device-Id', $device->device_uuid)->getJson('/api/v1/sync/reference')->assertOk()->json('data');
        $this->assertArrayHasKey('wards', $full['reference']);
        $this->assertContains('medications', $full['omitted_kinds']);
        $this->assertFalse($full['unchanged']);

        $none = $this->withHeader('X-Device-Id', $device->device_uuid)
            ->getJson('/api/v1/sync/reference?since='.$full['reference_version'])->assertOk()->json('data');
        $this->assertTrue($none['unchanged']);
        $this->assertSame([], (array) $none['reference']);
        $this->assertContains('medications', $none['omitted_kinds']);
    }
}
