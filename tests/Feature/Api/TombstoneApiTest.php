<?php

namespace Tests\Feature\Api;

use App\Enums\LabOrderStatus;
use App\Enums\RadiologyOrderStatus;
use App\Models\Device;
use App\Models\Hospital;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\Patient;
use App\Models\RadiologyOrder;
use App\Models\StockItem;
use App\Models\SyncTombstone;
use App\Models\User;
use App\Models\Visit;
use App\Services\LabService;
use App\Support\CurrentHospital;
use App\Support\SyncRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** sync_tombstones: written by observers, served as `removed` in pull and bootstrap, pruned after 90 days. */
class TombstoneApiTest extends TestCase
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

    private function device(User $user): Device
    {
        return Device::create([
            'hospital_id' => $user->hospital_id, 'user_id' => $user->id,
            'device_uuid' => (string) Str::uuid(), 'label' => 'tab', 'registered_at' => now(),
        ]);
    }

    private function pull(Device $d, ?string $cursor = null)
    {
        return $this->withHeader('X-Device-Id', $d->device_uuid)
            ->getJson('/api/v1/sync/pull'.($cursor ? '?cursor='.urlencode($cursor) : ''));
    }

    private function labOrderWithItem(?Visit $visit = null, LabOrderStatus $status = LabOrderStatus::Processing): LabOrder
    {
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $visit ??= Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $patient->id]);
        $order = LabOrder::create([
            'uuid' => (string) Str::uuid(), 'hospital_id' => $this->hospital->id,
            'visit_id' => $visit->id, 'patient_id' => $visit->patient_id, 'status' => $status,
        ]);
        LabOrderItem::create([
            'uuid' => (string) Str::uuid(), 'hospital_id' => $this->hospital->id,
            'lab_order_id' => $order->id, 'name' => 'Haemoglobin', 'price' => '10000',
        ]);

        return $order->fresh();
    }

    public function test_deleting_a_patient_writes_a_tombstone_and_restoring_clears_it(): void
    {
        $p = Patient::factory()->create(['hospital_id' => $this->hospital->id]);

        $p->delete();
        $row = SyncTombstone::where('entity', 'patients')->where('uuid', $p->uuid)->firstOrFail();
        $this->assertSame('deleted', $row->reason);
        $this->assertGreaterThan(0, $row->revision);

        $p->restore();
        $this->assertSame(0, SyncTombstone::where('entity', 'patients')->where('uuid', $p->uuid)->count());
    }

    public function test_a_record_without_a_uuid_is_named_by_its_id(): void
    {
        $service = \App\Models\Service::factory()->create(['hospital_id' => $this->hospital->id]);
        $service->delete();

        $this->assertSame((string) $service->id, SyncTombstone::where('entity', 'services')->value('uuid'));
    }

    public function test_closing_a_lab_order_removes_it_and_its_items_from_the_worklist_stream(): void
    {
        $order = $this->labOrderWithItem();
        $item = $order->items()->first();
        $tech = $this->staff('lab_technician');
        $device = $this->device($tech);
        Sanctum::actingAs($tech);

        // The device pulls the bench item first...
        $first = $this->pull($device)->assertOk();
        $this->assertContains($item->uuid, array_column(array_column($first->json('data.changes'), 'record'), 'uuid'));
        $cursor = $first->json('data.next_cursor');
        $this->assertSame([], $first->json('data.removed'));

        // ...then the order is completed: the bench item leaves the stream.
        app(LabService::class)->transition($order, LabOrderStatus::Completed);

        $second = $this->pull($device, $cursor)->assertOk();
        $removed = collect($second->json('data.removed'));
        $this->assertTrue($removed->contains(fn ($r) => $r['entity'] === 'lab_items' && $r['uuid'] === $item->uuid && $r['reason'] === 'closed'));
        $this->assertTrue($removed->contains(fn ($r) => $r['entity'] === 'lab_orders' && $r['uuid'] === $order->uuid && $r['reason'] === 'closed'));
        $this->assertNotEmpty($removed->first()['at']);

        // It is told once: the next page from the new cursor has nothing.
        $third = $this->pull($device, $second->json('data.next_cursor'))->assertOk();
        $this->assertSame([], $third->json('data.removed'));
    }

    public function test_cancelling_also_closes_and_radiology_closes_when_reported(): void
    {
        $order = $this->labOrderWithItem(null, LabOrderStatus::Ordered);
        app(LabService::class)->transition($order, LabOrderStatus::Cancelled);
        $this->assertSame(1, SyncTombstone::where('entity', 'lab_orders')->where('uuid', $order->uuid)->count());

        $visit = Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => Patient::factory()->create(['hospital_id' => $this->hospital->id])->id]);
        $rad = RadiologyOrder::create([
            'uuid' => (string) Str::uuid(), 'hospital_id' => $this->hospital->id,
            'visit_id' => $visit->id, 'patient_id' => $visit->patient_id, 'status' => RadiologyOrderStatus::Performed,
        ]);
        $rad->update(['status' => RadiologyOrderStatus::Reported]);

        $this->assertSame('closed', SyncTombstone::where('entity', 'radiology_orders')->where('uuid', $rad->uuid)->value('reason'));
    }

    public function test_a_tombstone_is_written_once_per_record(): void
    {
        $order = $this->labOrderWithItem();
        $order->update(['status' => LabOrderStatus::Completed]);
        $order->delete();

        $this->assertSame(1, SyncTombstone::where('entity', 'lab_orders')->where('uuid', $order->uuid)->count());
    }

    public function test_removals_arrive_in_bootstrap_after_since_and_nothing_before(): void
    {
        $p = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        Sanctum::actingAs($this->staff('hospital_admin'));
        $since = $this->getJson('/api/v1/bootstrap')->json('data.revision');

        $p->delete();
        $data = $this->getJson('/api/v1/bootstrap?since='.$since)->assertOk()->json('data');

        $this->assertFalse($data['unchanged']);
        $this->assertSame([['entity' => 'patients', 'uuid' => $p->uuid]], array_map(fn ($r) => ['entity' => $r['entity'], 'uuid' => $r['uuid']], $data['removed']));

        $later = $this->getJson('/api/v1/bootstrap?since='.$data['revision'])->json('data');
        $this->assertSame([], $later['removed']);
        $this->assertTrue($later['unchanged']);
    }

    public function test_removals_are_filtered_by_permission(): void
    {
        $p = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $item = StockItem::factory()->create(['hospital_id' => $this->hospital->id]);
        $p->delete();
        $item->delete();

        Sanctum::actingAs($this->staff('pharmacist'));
        $entities = array_column($this->getJson('/api/v1/bootstrap?since=1')->json('data.removed'), 'entity');
        $this->assertContains('stock_items', $entities);
        $this->assertContains('patients', $entities);

        Sanctum::actingAs($this->staff('lab_technician'));
        $entities = array_column($this->getJson('/api/v1/bootstrap?since=1')->json('data.removed'), 'entity');
        $this->assertNotContains('stock_items', $entities);
        $this->assertContains('patients', $entities);
    }

    public function test_one_hospital_never_hears_of_anothers_removals(): void
    {
        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $theirs = Patient::factory()->create(['hospital_id' => $other->id]);
        $theirs->delete();
        app(CurrentHospital::class)->set($this->hospital->id);

        Sanctum::actingAs($this->staff('hospital_admin'));
        $this->assertSame([], $this->getJson('/api/v1/bootstrap?since=1')->json('data.removed'));

        $nurse = $this->staff('nurse');
        $device = $this->device($nurse);
        Sanctum::actingAs($nurse);
        $this->assertSame([], $this->pull($device)->json('data.removed'));

        $this->assertSame(1, SyncTombstone::withoutGlobalScopes()->where('hospital_id', $other->id)->count());
        $this->assertSame(0, SyncTombstone::count());
    }

    public function test_pruning_drops_only_rows_past_ninety_days_and_runs_at_most_twice_a_day(): void
    {
        $p = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $q = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $p->delete();
        $q->delete();
        SyncTombstone::where('uuid', $p->uuid)->update(['deleted_at' => now()->subDays(91)]);
        SyncTombstone::where('uuid', $q->uuid)->update(['deleted_at' => now()->subDays(89)]);

        $this->assertSame(1, SyncTombstone::pruneIfDue());
        $this->assertSame([$q->uuid], SyncTombstone::pluck('uuid')->all());

        // A cron that fires every few minutes must not re-scan each time.
        $this->assertNull(SyncTombstone::pruneIfDue());
        $this->travel(13)->hours();
        $this->assertSame(0, SyncTombstone::pruneIfDue());
    }

    public function test_pull_pages_keep_removals_in_revision_order_with_changes(): void
    {
        $nurse = $this->staff('nurse');
        $device = $this->device($nurse);
        Sanctum::actingAs($nurse);

        config(['offline.window.page_size' => 2]);
        $patients = Patient::factory()->count(3)->create(['hospital_id' => $this->hospital->id]);
        $patients[0]->delete();

        $seen = [];
        $removed = [];
        $cursor = null;
        do {
            $page = $this->pull($device, $cursor)->assertOk();
            foreach ($page->json('data.changes') as $c) {
                $seen[] = $c['record']['uuid'];
            }
            foreach ($page->json('data.removed') as $r) {
                $removed[] = $r['uuid'];
            }
            $cursor = $page->json('data.next_cursor');
        } while ($page->json('data.has_more'));

        $this->assertContains($patients[0]->uuid, $removed);
        $this->assertEqualsCanonicalizing(array_map(fn ($p) => $p->uuid, $patients->all()), array_unique($seen));
        $this->assertGreaterThan(0, SyncRevision::current());
    }
}
