<?php

namespace Tests\Feature\Offline;

use App\Enums\LabOrderStatus;
use App\Models\Device;
use App\Models\Hospital;
use App\Models\LabOrderItem;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\Service;
use App\Models\SyncConflict;
use App\Models\User;
use App\Models\Visit;
use App\Services\LabService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reporting a laboratory result from a device.
 *
 * The strictest entity in the offline system. The plan (§10.1) puts it
 * plainly: a result overwritten by a stale device is a patient-safety event —
 * somebody is dosed on a potassium reading or sent home on a haemoglobin. So
 * there is no merge, no field reconciliation, and no benefit of the doubt.
 */
class LabResultSyncTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $tech;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->tech = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'lab_technician']);
        $this->tech->syncSpatieRole();

        $this->device = Device::create([
            'hospital_id' => $this->hospital->id,
            'user_id' => $this->tech->id,
            'device_uuid' => (string) Str::uuid(),
            'label' => 'Bench tablet',
            'registered_at' => now(),
        ]);
    }

    /** A test somebody ordered, waiting for a number. */
    private function pendingItem(): LabOrderItem
    {
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $visit = Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $patient->id]);

        Service::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Haemoglobin', 'price' => '15000.00']);
        $test = LabTest::factory()->create([
            'hospital_id' => $this->hospital->id,
            'name' => 'Haemoglobin', 'unit' => 'g/dL', 'reference_range' => '12-16', 'price' => '15000.00',
        ]);

        $order = app(LabService::class)->order($visit->fresh(), [$test->id], null, $this->tech->id);

        return $order->items()->firstOrFail();
    }

    private function op(array $payload, array $extra = []): array
    {
        return array_merge([
            'operation_id' => strtoupper((string) Str::ulid()),
            'entity' => 'lab_items',
            'entity_uuid' => $payload['uuid'],
            'operation' => 'update',
            'payload' => $payload,
        ], $extra);
    }

    private function push(array $ops, ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->tech);

        return $this->withHeader('X-Device-Id', $this->device->device_uuid)
            ->postJson(route('api.sync.push'), ['operations' => $ops]);
    }

    // ── The happy path ───────────────────────────────────────────────────

    public function test_a_result_entered_at_the_bench_reaches_the_order(): void
    {
        $item = $this->pendingItem();

        $result = $this->push([$this->op([
            'uuid' => $item->uuid, 'result_value' => '13.4', 'result_flag' => 'normal',
            'result_notes' => 'Sample slightly haemolysed.',
        ], ['base_version' => (int) $item->version])])->assertOk()->json('data.results.0');

        $this->assertSame('accepted', $result['status']);

        $item->refresh();

        $this->assertSame('13.4', $item->result_value);
        $this->assertSame('Sample slightly haemolysed.', $item->result_notes);
        $this->assertSame($this->tech->id, $item->resulted_by);
        // The SERVER's clock stamps when it was reported, and comes back so
        // the device stops showing its own guess.
        $this->assertNotNull($item->resulted_at);
        $this->assertSame($item->resulted_at->toIso8601String(), $result['assigned']['resulted_at']);
        $this->assertSame($this->device->id, $item->origin_device_id);
        $this->assertSame(2, (int) $item->version);
    }

    // ── The strict part ──────────────────────────────────────────────────

    /**
     * Somebody else reported it while this device was away.
     *
     * Refused and raised — never merged. Two benches reporting different
     * numbers for one specimen is not a formatting disagreement.
     */
    public function test_a_stale_device_never_overwrites_a_result_somebody_else_reported(): void
    {
        $item = $this->pendingItem();
        $base = (int) $item->version;

        // The day bench reports it online.
        app(LabService::class)->recordResult($item, ['result_value' => '9.1', 'result_flag' => 'low'], $this->tech->id);
        $item->forceFill(['version' => $base + 1])->saveQuietly();

        $result = $this->push([$this->op([
            'uuid' => $item->uuid, 'result_value' => '13.4', 'result_flag' => 'normal',
        ], ['base_version' => $base])])->assertOk()->json('data.results.0');

        $this->assertSame('conflict', $result['status']);
        $this->assertSame('manual', $result['conflict']['strategy']);
        $this->assertSame(['result_value'], $result['conflict']['contested']);

        // Untouched. This is the assertion the handler exists for.
        $this->assertSame('9.1', $item->fresh()->result_value);

        $this->assertSame(1, SyncConflict::count());
        $this->assertSame(['result_value'], SyncConflict::firstOrFail()->contested_fields);
    }

    /**
     * A row that predates versioning has version 1 AND a result.
     *
     * Overwriting it because the numbers happen to line up is exactly the
     * failure this handler exists to prevent.
     */
    public function test_an_existing_result_is_not_overwritten_by_an_operation_with_no_base(): void
    {
        $item = $this->pendingItem();
        app(LabService::class)->recordResult($item, ['result_value' => '9.1'], $this->tech->id);

        $result = $this->push([$this->op([
            'uuid' => $item->uuid, 'result_value' => '13.4',
        ])])->assertOk()->json('data.results.0');

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('already_reported', $result['reason_code']);
        $this->assertStringContainsString('bench worklist', $result['message']);
        $this->assertSame('9.1', $item->fresh()->result_value);
    }

    public function test_a_result_cannot_be_invented_from_a_device(): void
    {
        $result = $this->push([$this->op([
            'uuid' => (string) Str::uuid(), 'result_value' => '13.4',
        ], ['operation' => 'create'])])->assertOk()->json('data.results.0');

        // The line exists because a doctor ordered the test. A device does not
        // get to invent work nobody asked for.
        $this->assertSame('rejected', $result['status']);
        $this->assertSame('unsupported_operation', $result['reason_code']);
        $this->assertSame(0, LabOrderItem::whereNotNull('result_value')->count());
    }

    public function test_a_result_for_a_test_that_is_no_longer_here_says_so(): void
    {
        $result = $this->push([$this->op([
            'uuid' => (string) Str::uuid(), 'result_value' => '13.4',
        ])])->assertOk()->json('data.results.0');

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('not_found', $result['reason_code']);
    }

    public function test_nothing_can_be_added_to_a_closed_order(): void
    {
        $item = $this->pendingItem();
        $order = $item->order;

        app(LabService::class)->transition($order, LabOrderStatus::Collected, $this->tech->id);
        app(LabService::class)->transition($order->fresh(), LabOrderStatus::Cancelled, $this->tech->id);

        $result = $this->push([$this->op([
            'uuid' => $item->uuid, 'result_value' => '13.4',
        ], ['base_version' => (int) $item->version])])->assertOk()->json('data.results.0');

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('parent_closed', $result['reason_code']);
        $this->assertNull($item->fresh()->result_value);
    }

    public function test_an_empty_result_is_refused(): void
    {
        $item = $this->pendingItem();

        $result = $this->push([$this->op([
            'uuid' => $item->uuid, 'result_value' => '',
        ], ['base_version' => (int) $item->version])])->assertOk()->json('data.results.0');

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('validation_failed', $result['reason_code']);
        $this->assertArrayHasKey('result_value', $result['errors']);
    }

    // ── Who may ──────────────────────────────────────────────────────────

    public function test_only_somebody_who_may_work_the_bench_can_report_a_result(): void
    {
        $item = $this->pendingItem();

        $nurse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $nurse->syncSpatieRole();

        $result = $this->push([$this->op([
            'uuid' => $item->uuid, 'result_value' => '13.4',
        ], ['base_version' => (int) $item->version])], $nurse)->assertOk()->json('data.results.0');

        // The SAME ability the bench worklist gates on — `lab.process`.
        $this->assertSame('rejected', $result['status']);
        $this->assertSame('forbidden', $result['reason_code']);
        $this->assertNull($item->fresh()->result_value);
    }

    // ── Replays ──────────────────────────────────────────────────────────

    public function test_replaying_a_result_does_not_report_it_twice(): void
    {
        $item = $this->pendingItem();

        $op = $this->op(['uuid' => $item->uuid, 'result_value' => '13.4'], ['base_version' => (int) $item->version]);

        $first = $this->push([$op])->json('data.results.0');
        $replay = $this->push([$op])->json('data.results.0');

        $this->assertSame('accepted', $first['status']);
        $this->assertSame('already_processed', $replay['status']);

        // Version moved once, not twice — a second application would have made
        // it 3 and left an audit trail claiming two reports.
        $this->assertSame(2, (int) $item->fresh()->version);
        $this->assertSame('13.4', $item->fresh()->result_value);
    }

    // ── The worklist reaching the bench ──────────────────────────────────

    public function test_a_bench_device_pulls_the_tests_waiting_to_be_reported(): void
    {
        $item = $this->pendingItem();

        Sanctum::actingAs($this->tech);
        $changes = $this->withHeader('X-Device-Id', $this->device->device_uuid)
            ->getJson(route('api.sync.pull'))->assertOk()->json('data.changes');

        $labItems = array_values(array_filter($changes, fn ($c) => $c['entity'] === 'lab_items'));

        $this->assertCount(1, $labItems);
        $this->assertSame($item->uuid, $labItems[0]['record']['uuid']);
        $this->assertSame('Haemoglobin', $labItems[0]['record']['name']);
        $this->assertSame('12-16', $labItems[0]['record']['reference_range']);
        $this->assertNull($labItems[0]['record']['result_value']);
    }

    public function test_what_a_test_costs_never_reaches_the_bench(): void
    {
        $this->pendingItem();

        Sanctum::actingAs($this->tech);
        $body = $this->withHeader('X-Device-Id', $this->device->device_uuid)
            ->getJson(route('api.sync.pull'))->getContent();

        // A bench does not need to know the price, and money never reaches a
        // device (invariant I-12).
        $this->assertStringNotContainsString('"price"', $body);
        $this->assertStringNotContainsString('15000', $body);
    }

    public function test_a_completed_order_drops_off_the_worklist(): void
    {
        $item = $this->pendingItem();
        $order = $item->order;

        app(LabService::class)->transition($order, LabOrderStatus::Collected, $this->tech->id);
        app(LabService::class)->transition($order->fresh(), LabOrderStatus::Processing, $this->tech->id);
        app(LabService::class)->recordResult($item, ['result_value' => '13.4'], $this->tech->id);
        app(LabService::class)->transition($order->fresh(), LabOrderStatus::Completed, $this->tech->id);

        Sanctum::actingAs($this->tech);
        $changes = $this->withHeader('X-Device-Id', $this->device->device_uuid)
            ->getJson(route('api.sync.pull'))->json('data.changes');

        // Reported is history. A device holding every result ever produced is
        // holding far more patient data than the work needs (plan §15).
        $this->assertSame([], array_values(array_filter($changes, fn ($c) => $c['entity'] === 'lab_items')));
    }

    public function test_a_nurse_device_receives_no_lab_worklist_at_all(): void
    {
        $this->pendingItem();

        $nurse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $nurse->syncSpatieRole();
        $nurseDevice = Device::create([
            'hospital_id' => $this->hospital->id, 'user_id' => $nurse->id,
            'device_uuid' => (string) Str::uuid(), 'label' => 'Ward tablet', 'registered_at' => now(),
        ]);

        Sanctum::actingAs($nurse);
        $entities = array_column(
            $this->withHeader('X-Device-Id', $nurseDevice->device_uuid)
                ->getJson(route('api.sync.pull'))->json('data.changes'),
            'entity',
        );

        $this->assertNotContains('lab_items', $entities);
    }

    public function test_the_server_accepts_it_on_the_entity_list(): void
    {
        Sanctum::actingAs($this->tech);

        $accepts = $this->withHeader('X-Device-Id', $this->device->device_uuid)
            ->getJson(route('api.sync.status'))->json('data.accepts');

        $this->assertContains('lab_items', $accepts);
    }

    // ── The bench, as the app reads and works it ─────────────────────────

    /** The web worklist's own limits: a flag the web cannot show is refused. */
    public function test_a_result_is_held_to_the_bench_worklists_rules(): void
    {
        $item = $this->pendingItem();

        $result = $this->push([$this->op([
            'uuid' => $item->uuid, 'result_value' => str_repeat('9', 121), 'result_flag' => 'weird',
        ], ['base_version' => (int) $item->version])])->assertOk()->json('data.results.0');

        $this->assertSame('rejected', $result['status']);
        $this->assertArrayHasKey('result_value', $result['errors']);
        $this->assertArrayHasKey('result_flag', $result['errors']);
        $this->assertNull($item->fresh()->result_value);
    }

    /** The worklist: outstanding oldest first, the tally, and each line's version. */
    public function test_the_worklist_filters_and_counts_like_the_web(): void
    {
        $item = $this->pendingItem();
        $order = $item->order;
        Sanctum::actingAs($this->tech);

        $res = $this->getJson('/api/v1/lab-orders?outstanding=1')->assertOk();

        $res->assertJsonPath('data.0.uuid', $order->uuid)
            ->assertJsonPath('data.0.items.0.uuid', $item->uuid)
            ->assertJsonPath('data.0.items.0.version', (int) $item->version)
            ->assertJsonPath('data.0.next_statuses', ['collected', 'cancelled'])
            ->assertJsonPath('data.0.overdue', false)
            ->assertJsonPath('meta.tally.ordered', 1)
            ->assertJsonPath('meta.old_hours', \App\Support\LabBench::OLD_HOURS);

        $this->getJson('/api/v1/lab-orders?q='.urlencode($order->patient->last_name))->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/lab-orders?q=nobody-by-that-name')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/lab-orders?status=completed')->assertJsonCount(0, 'data');
    }

    /** Moving an order: the bench may, along LabOrderStatus's rules only. */
    public function test_an_order_moves_only_where_its_status_allows(): void
    {
        $order = $this->pendingItem()->order;
        Sanctum::actingAs($this->tech);

        $this->postJson("/api/v1/lab-orders/{$order->uuid}/transition", ['status' => 'completed'])
            ->assertStatus(422);
        $this->postJson("/api/v1/lab-orders/{$order->uuid}/transition", ['status' => 'collected'])
            ->assertOk()
            ->assertJsonPath('data.status', 'collected')
            ->assertJsonPath('data.next_statuses', ['processing', 'cancelled']);

        $doctor = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor']);
        $doctor->syncSpatieRole();
        Sanctum::actingAs($doctor);
        $this->postJson("/api/v1/lab-orders/{$order->uuid}/transition", ['status' => 'processing'])->assertStatus(403);
    }
}
