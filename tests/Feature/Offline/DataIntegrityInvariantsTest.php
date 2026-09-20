<?php

namespace Tests\Feature\Offline;

use App\Models\Bed;
use App\Models\Device;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\SyncOperation;
use App\Models\User;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The data-integrity invariants, named (plan §18).
 *
 * Most are already proven somewhere — by `OperationLedgerTest`, `SyncPushTest`,
 * `SyncPullTest` or the Vitest suites. This file is the REGISTER: one test per
 * invariant, each saying which one it is, so that "are all fifteen still
 * held?" is a question with an answer rather than an archaeology exercise.
 *
 * Where an invariant lives entirely on the client, the test here asserts the
 * client code still has the property that makes it true, and names the Vitest
 * file that exercises it.
 */
class DataIntegrityInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $nurse;

    private User $clerk;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->nurse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $this->nurse->syncSpatieRole();

        $this->clerk = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'receptionist']);
        $this->clerk->syncSpatieRole();

        $this->device = Device::create([
            'hospital_id' => $this->hospital->id,
            'user_id' => $this->clerk->id,
            'device_uuid' => (string) Str::uuid(),
            'label' => 'Test device',
            'registered_at' => now(),
        ]);
    }

    private function op(string $entity, string $operation, array $payload, array $extra = []): array
    {
        return array_merge([
            'operation_id' => strtoupper((string) Str::ulid()),
            'entity' => $entity,
            'entity_uuid' => $payload['uuid'] ?? (string) Str::uuid(),
            'operation' => $operation,
            'payload' => $payload,
        ], $extra);
    }

    private function push(array $ops, ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->clerk);

        return $this->withHeader('X-Device-Id', $this->device->device_uuid)
            ->postJson(route('api.sync.push'), ['operations' => $ops]);
    }

    // ── I-1 · an operation_id is applied at most once ────────────────────

    public function test_i1_an_operation_id_is_applied_at_most_once(): void
    {
        $op = $this->op('patients', 'create', ['uuid' => (string) Str::uuid(), 'first_name' => 'Amina', 'last_name' => 'Nakato']);

        $this->push([$op]);
        $this->push([$op]);
        $this->push([$op]);

        $this->assertSame(1, Patient::count());
        $this->assertSame(1, SyncOperation::where('operation_id', $op['operation_id'])->count());
    }

    // ── I-2 · a replay returns the original result ───────────────────────

    public function test_i2_a_replay_returns_the_original_result_and_creates_nothing(): void
    {
        $op = $this->op('patients', 'create', ['uuid' => (string) Str::uuid(), 'first_name' => 'Amina', 'last_name' => 'Nakato']);

        $first = $this->push([$op])->json('data.results.0');
        $replay = $this->push([$op])->json('data.results.0');

        $this->assertSame('accepted', $first['status']);
        $this->assertSame('already_processed', $replay['status']);
        $this->assertSame($first['server_id'], $replay['server_id']);
        $this->assertSame($first['assigned']['patient_no'], $replay['assigned']['patient_no']);
    }

    // ── I-3 · an operation leaves the outbox only by syncing or cancelling

    public function test_i3_the_outbox_never_silently_discards_work(): void
    {
        $source = File::get(resource_path('js/offline/outbox.js'));

        // An exhausted retry budget makes an operation `failed`, which is a
        // state it sits in visibly. It is never deleted.
        $this->assertStringContainsString('status: exhausted ? OP_FAILED : OP_PENDING', $source);
        $this->assertStringNotContainsString('.delete(', $source, 'Nothing in the outbox deletes an operation.');
        $this->assertStringContainsString('operation_cancelled', $source, 'A cancellation is audited.');
        // Exercised by tests/js/foundation.test.js and sync-engine.test.js.
    }

    // ── I-4 · the cursor never advances before the page is committed ─────

    public function test_i4_the_cursor_advances_only_after_the_page_is_saved(): void
    {
        $source = File::get(resource_path('js/offline/sync-engine.js'));

        // The order in `pullAll` is: applyChanges, THEN saveCursor. Reversing
        // them loses the page for ever, because the server never re-sends it.
        $applyAt = strpos($source, 'await this.applyChanges(changes);');
        $saveAt = strpos($source, 'await this.saveCursor(cursor);');

        $this->assertNotFalse($applyAt);
        $this->assertNotFalse($saveAt);
        $this->assertLessThan($saveAt, $applyAt, 'The page must be committed before the cursor moves.');

        // And the server never moves it on the device's behalf. Asserted by
        // doing it rather than by reading the code: a server that advanced the
        // cursor itself would skip a page the device failed to commit.
        Patient::factory()->count(3)->create(['hospital_id' => $this->hospital->id]);

        Sanctum::actingAs($this->clerk);
        $this->withHeader('X-Device-Id', $this->device->device_uuid)
            ->getJson(route('api.sync.pull'))
            ->assertOk();

        $this->assertNull($this->device->fresh()->pull_cursor);
    }

    // ── I-5 · entity write and enqueue are one transaction ───────────────

    public function test_i5_a_record_and_its_operation_are_written_together(): void
    {
        $source = File::get(resource_path('js/offline/repository.js'));

        // One `db.transaction` spanning the store, the outbox and the audit.
        $this->assertStringContainsString(
            "await this.db.transaction('rw', this.db[store], this.db.outbox, this.db.audit",
            $source,
        );
        // Exercised by "leaves nothing behind when the transaction fails".
    }

    // ── I-6 · a service-worker update never touches the local database ───

    public function test_i6_the_service_worker_cannot_destroy_pending_work(): void
    {
        $worker = File::get(public_path('field-sw.js'));

        // True by construction: a worker with no database code cannot lose
        // anybody's work, however badly an update goes.
        $this->assertStringNotContainsString('indexedDB', $worker);
        $this->assertStringNotContainsString('Dexie', $worker);

        $registration = File::get(resource_path('js/offline/worker.js'));
        $this->assertStringNotContainsString('indexedDB', $registration);
    }

    // ── I-7 · a browser restart preserves pending operations ─────────────

    public function test_i7_pending_work_survives_a_restart(): void
    {
        // IndexedDB is durable by nature; what could lose the work is code
        // that clears it on boot. There is none, and the boot path explicitly
        // RECOVERS in-flight operations rather than discarding them.
        $source = File::get(resource_path('js/offline/index.js'));

        $this->assertStringContainsString('recoverInFlight', $source);
        $this->assertStringContainsString('It will be sent again.', $source);
        // Exercised by tests/js/sync-engine.test.js "durability across a full round".
    }

    // ── I-8 · an offline-created record keeps its uuid ───────────────────

    public function test_i8_the_server_never_reassigns_a_uuid_the_device_minted(): void
    {
        $uuid = (string) Str::uuid();

        $this->push([$this->op('patients', 'create', ['uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato'])]);

        $this->assertSame($uuid, Patient::firstOrFail()->uuid);
    }

    // ── I-9 · a conflict never silently overwrites protected data ────────

    public function test_i9_a_contested_clinical_field_is_never_overwritten(): void
    {
        $uuid = (string) Str::uuid();
        $this->push([$this->op('patients', 'create', [
            'uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato', 'dob' => '1990-01-01',
        ])]);

        $patient = Patient::firstOrFail();
        $patient->update(['dob' => '1991-05-05']);
        $patient->forceFill(['version' => 2])->saveQuietly();

        $result = $this->push([$this->op('patients', 'update', [
            'uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato', 'dob' => '1992-09-09',
        ], ['base_version' => 1, 'base_fields' => ['dob' => '1990-01-01']])])->json('data.results.0');

        $this->assertSame('conflict', $result['status']);
        $this->assertSame('1991-05-05', $patient->fresh()->dob->format('Y-m-d'));
    }

    // ── I-10 · a rejected operation stays recoverable ────────────────────

    public function test_i10_a_refusal_keeps_the_payload_on_the_device(): void
    {
        $source = File::get(resource_path('js/offline/sync-engine.js'));

        $this->assertStringContainsString('invariant I-10', $source);
        // The payload is never cleared when marking an operation rejected.
        $this->assertStringNotContainsString('payload: null', $source);
    }

    // ── I-11 · no human-facing number is allocated on a device ───────────

    public function test_i11_a_patient_number_is_only_ever_issued_by_the_server(): void
    {
        $uuid = (string) Str::uuid();

        // The device sends a number it invented. The server ignores it.
        $this->push([$this->op('patients', 'create', [
            'uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato',
            'patient_no' => 'DEVICE-MADE-THIS-UP',
        ])]);

        $patient = Patient::firstOrFail();

        $this->assertNotSame('DEVICE-MADE-THIS-UP', $patient->patient_no);
        $this->assertMatchesRegularExpression('/^PT-\d{4}-\d{6}[A-Z]$/', $patient->patient_no);
    }

    // ── I-12 · no balance is ever computed on a device ───────────────────

    public function test_i12_nothing_a_device_can_pull_carries_a_balance(): void
    {
        $ward = Ward::factory()->create(['hospital_id' => $this->hospital->id]);
        $bed = Bed::factory()->create(['hospital_id' => $this->hospital->id, 'ward_id' => $ward->id]);
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        app(AdmissionService::class)->admit($patient, $bed, [], $this->nurse->id);

        Sanctum::actingAs($this->nurse);
        $nurseDevice = Device::create([
            'hospital_id' => $this->hospital->id, 'user_id' => $this->nurse->id,
            'device_uuid' => (string) Str::uuid(), 'label' => 'Nurse device', 'registered_at' => now(),
        ]);

        $body = $this->withHeader('X-Device-Id', $nurseDevice->device_uuid)
            ->getJson(route('api.sync.pull'))->getContent();

        // Not one of these may reach a device: they are caches of append-only
        // ledgers computed under row locks, and a figure a device showed would
        // be one the server disagrees with (plan §10.3).
        foreach (['balance_after', 'amount_paid', 'current_quantity', 'max_credit', 'bed_charge_total'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "A pull payload carried `{$forbidden}`.");
        }

        $reference = $this->withHeader('X-Device-Id', $nurseDevice->device_uuid)
            ->getJson(route('api.sync.reference'))->getContent();

        $this->assertStringNotContainsString('current_quantity', $reference);
    }

    // ── I-13 · signing out with unsent work needs confirmation ───────────

    public function test_i13_signing_out_refuses_while_work_is_unsent(): void
    {
        $source = File::get(resource_path('js/offline/index.js'));

        $this->assertStringContainsString('discardUnsynced = false', $source);
        $this->assertStringContainsString('return { wiped: false, outstanding }', $source);
        $this->assertStringContainsString('invariant I-13', $source);
    }

    // ── I-14 · a local migration preserves the outbox ────────────────────

    public function test_i14_the_local_schema_is_versioned_append_only(): void
    {
        $source = File::get(resource_path('js/offline/db.js'));

        // A new version never redefines an old one: a device two versions
        // behind replays every step, and rewriting a past step migrates it
        // through a schema that never existed.
        $this->assertStringContainsString('Dexie versions are append-only', $source);
        $this->assertSame(1, substr_count($source, 'db.version(1).stores('));
        // Exercised by tests/js/migrations.test.js, which carries 50 pending
        // operations from v1 to v3 and counts them.
    }

    // ── I-15 · authorisation is per operation, not per batch ─────────────

    public function test_i15_every_operation_in_a_batch_is_authorised_separately(): void
    {
        $ward = Ward::factory()->create(['hospital_id' => $this->hospital->id]);
        $bed = Bed::factory()->create(['hospital_id' => $this->hospital->id, 'ward_id' => $ward->id]);
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $admission = app(AdmissionService::class)->admit($patient, $bed, [], $this->nurse->id);

        // Reception may register a patient but not record vitals. Both go in
        // one batch, under one token.
        $results = $this->push([
            $this->op('patients', 'create', ['uuid' => (string) Str::uuid(), 'first_name' => 'A', 'last_name' => 'B']),
            $this->op('vitals', 'create', ['uuid' => (string) Str::uuid(), 'admission_uuid' => $admission->uuid, 'pulse' => 70]),
            $this->op('patients', 'create', ['uuid' => (string) Str::uuid(), 'first_name' => 'C', 'last_name' => 'D']),
        ])->json('data.results');

        $this->assertSame('accepted', $results[0]['status']);
        $this->assertSame('rejected', $results[1]['status']);
        $this->assertSame('forbidden', $results[1]['reason_code']);
        $this->assertSame('accepted', $results[2]['status']);
    }

    // ── The register itself ──────────────────────────────────────────────

    /**
     * Every invariant in the plan has a test here.
     *
     * A guard against the quiet failure mode of a register: somebody adds
     * I-16 to the document and nobody notices it has no test.
     */
    public function test_every_invariant_in_the_plan_is_covered_here(): void
    {
        $plan = File::get(base_path('docs/OFFLINE_FIRST_IMPLEMENTATION_PLAN.md'));

        preg_match_all('/\| I-(\d+) \|/', $plan, $matches);
        $declared = array_map('intval', $matches[1]);

        $this->assertNotEmpty($declared, 'The plan should declare its invariants in a table.');

        $covered = [];
        foreach (get_class_methods($this) as $method) {
            if (preg_match('/^test_i(\d+)_/', $method, $m)) {
                $covered[] = (int) $m[1];
            }
        }

        sort($declared);
        sort($covered);

        $this->assertSame(
            $declared,
            $covered,
            'Every invariant in the plan needs a test here. Missing: '
            .implode(', ', array_map(fn ($n) => "I-{$n}", array_diff($declared, $covered))),
        );
    }
}
