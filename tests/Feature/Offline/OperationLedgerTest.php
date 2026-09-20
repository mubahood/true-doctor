<?php

namespace Tests\Feature\Offline;

use App\Models\Device;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\SyncOperation;
use App\Models\User;
use App\Services\Sync\OperationLedger;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The idempotency gate — invariants I-1 and I-2.
 *
 * This is the test that decides whether offline sync is safe at all. A network
 * can fail after the server commits and before the browser hears about it; the
 * device cannot tell that from "never arrived", so it retries. Everything else
 * in the offline system assumes a retry is free.
 */
class OperationLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $user;

    private Device $device;

    private OperationLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->user = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $this->user->syncSpatieRole();

        $this->device = Device::create([
            'hospital_id' => $this->hospital->id,
            'user_id' => $this->user->id,
            'device_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'label' => 'Ward tablet',
            'registered_at' => now(),
        ]);

        $this->ledger = app(OperationLedger::class);
    }

    /** @param array<string,mixed> $overrides */
    private function operation(array $overrides = []): array
    {
        return array_merge([
            'operation_id' => strtoupper(\Illuminate\Support\Str::ulid()->toBase32()),
            'entity' => 'patients',
            'entity_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'operation' => 'create',
            'payload' => ['first_name' => 'Amina', 'last_name' => 'Nakato'],
            'base_version' => null,
            'user_id' => $this->user->id,
            'device_id' => $this->device->id,
            'client_created_at' => now()->toIso8601String(),
        ], $overrides);
    }

    /** Create a real patient, so the test measures real rows and not a counter. */
    private function createPatient(array $op): callable
    {
        return function () use ($op) {
            $patient = Patient::create([
                'uuid' => $op['entity_uuid'],
                'hospital_id' => $this->hospital->id,
                'patient_no' => 'PAT-'.substr($op['entity_uuid'], 0, 8),
                'first_name' => $op['payload']['first_name'],
                'last_name' => $op['payload']['last_name'],
            ]);

            return ['status' => 'accepted', 'server_id' => $patient->id, 'version' => 1,
                'assigned' => ['patient_no' => $patient->patient_no]];
        };
    }

    // ── I-1 · applied at most once ───────────────────────────────────────

    public function test_an_operation_runs_once_and_says_what_it_did(): void
    {
        $op = $this->operation();

        $out = $this->ledger->once($op, $this->createPatient($op));

        $this->assertFalse($out['replayed']);
        $this->assertSame('accepted', $out['result']['status']);
        $this->assertSame($op['operation_id'], $out['result']['operation_id']);
        $this->assertSame(1, Patient::count());
        $this->assertSame(1, SyncOperation::count());
    }

    /**
     * The scenario the whole design exists for: the server commits, the
     * response is lost, the device retries.
     */
    public function test_a_lost_response_does_not_create_a_second_record(): void
    {
        $op = $this->operation();

        $first = $this->ledger->once($op, $this->createPatient($op));

        // …response never reaches the browser. It sends the same operation.
        $replay = $this->ledger->once($op, function () {
            $this->fail('The work ran a second time — the gate did not hold.');
        });

        $this->assertTrue($replay['replayed']);
        $this->assertSame('accepted', $replay['result']['status']);
        $this->assertSame($first['result']['server_id'], $replay['result']['server_id']);
        $this->assertSame('PAT-'.substr($op['entity_uuid'], 0, 8), $replay['result']['assigned']['patient_no']);

        // Exactly one patient. This is the assertion that matters.
        $this->assertSame(1, Patient::count());
        $this->assertSame(1, SyncOperation::count());
    }

    public function test_ten_replays_still_produce_one_record(): void
    {
        $op = $this->operation();
        $this->ledger->once($op, $this->createPatient($op));

        for ($i = 0; $i < 10; $i++) {
            $out = $this->ledger->once($op, fn () => $this->fail('ran again'));
            $this->assertTrue($out['replayed']);
        }

        $this->assertSame(1, Patient::count());
    }

    /**
     * Two replays arriving together.
     *
     * A check-then-insert in PHP would let both through. The unique index is
     * what makes this safe, so the test inserts the claim row directly to
     * simulate the winner having committed a moment earlier.
     */
    public function test_a_concurrent_replay_is_refused_by_the_index_not_by_a_check(): void
    {
        $op = $this->operation();
        $ran = 0;

        $this->ledger->once($op, function () use ($op, &$ran) {
            $ran++;

            return $this->createPatient($op)();
        });

        // A second process with the same operation id. Even if it read the
        // table a microsecond before the first insert committed, the insert
        // itself is the gate.
        $this->ledger->once($op, function () use (&$ran) {
            $ran++;

            return ['status' => 'accepted'];
        });

        $this->assertSame(1, $ran);
        $this->assertSame(1, Patient::count());
    }

    public function test_an_operation_with_no_id_is_refused_rather_than_guessed_at(): void
    {
        $out = $this->ledger->once(['payload' => []], fn () => $this->fail('ran'));

        $this->assertSame('rejected', $out['result']['status']);
        $this->assertSame('missing_operation_id', $out['result']['reason_code']);
        $this->assertSame(0, SyncOperation::count());
    }

    // ── Failure keeps the claim ──────────────────────────────────────────

    public function test_work_that_throws_leaves_no_half_applied_record(): void
    {
        $op = $this->operation();

        $out = $this->ledger->once($op, function () use ($op) {
            Patient::create([
                'uuid' => $op['entity_uuid'],
                'hospital_id' => $this->hospital->id,
                'patient_no' => 'PAT-X',
                'first_name' => 'Half',
                'last_name' => 'Written',
            ]);

            throw new \RuntimeException('exploded after writing');
        });

        $this->assertSame('failed', $out['result']['status']);
        $this->assertSame('server_error', $out['result']['reason_code']);

        // The half-written patient must be gone. Without a transaction round
        // the work, a handler that writes two rows and fails on the second
        // leaves the first behind — and the retry then writes it again.
        $this->assertSame(0, Patient::count());

        // The claim survives — a retry must find the operation, not start
        // afresh while the first attempt is still unwinding.
        $this->assertSame(1, SyncOperation::count());
        $this->assertSame('failed', SyncOperation::first()->status);
    }

    public function test_a_claim_abandoned_by_a_dead_process_can_be_taken_over(): void
    {
        $op = $this->operation();

        // A process claimed this and never came back.
        SyncOperation::create([
            'operation_id' => $op['operation_id'],
            'hospital_id' => $this->hospital->id,
            'user_id' => $this->user->id,
            'device_id' => $this->device->id,
            'entity' => 'patients',
            'entity_uuid' => $op['entity_uuid'],
            'operation' => 'create',
            'status' => 'processing',
        ]);

        // Eloquent stamps created_at itself; ageing the row has to go round it.
        DB::table('sync_operations')->where('operation_id', $op['operation_id'])
            ->update(['created_at' => now()->subMinutes(30)]);

        $out = $this->ledger->once($op, $this->createPatient($op));

        $this->assertFalse($out['replayed']);
        $this->assertSame('accepted', $out['result']['status']);
        $this->assertSame(1, Patient::count());
    }

    public function test_a_claim_still_warm_tells_the_client_to_wait(): void
    {
        $op = $this->operation();

        SyncOperation::create([
            'operation_id' => $op['operation_id'],
            'hospital_id' => $this->hospital->id,
            'user_id' => $this->user->id,
            'entity' => 'patients',
            'entity_uuid' => $op['entity_uuid'],
            'operation' => 'create',
            'status' => 'processing',
        ]);

        $out = $this->ledger->once($op, fn () => $this->fail('ran while another attempt was in flight'));

        $this->assertSame('failed', $out['result']['status']);
        $this->assertSame('in_flight', $out['result']['reason_code']);
        $this->assertSame(0, Patient::count());
    }

    public function test_reclaim_frees_abandoned_claims_without_freeing_the_id(): void
    {
        SyncOperation::create([
            'operation_id' => 'STUCK01',
            'hospital_id' => $this->hospital->id,
            'user_id' => $this->user->id,
            'entity' => 'patients',
            'entity_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'operation' => 'create',
            'status' => 'processing',
        ]);
        DB::table('sync_operations')->where('operation_id', 'STUCK01')
            ->update(['created_at' => now()->subHour()]);

        $this->assertSame(1, $this->ledger->reclaimStale());

        // Marked failed, NOT deleted: the id must stay taken or a replay would
        // sail past the gate and apply the work twice.
        $this->assertSame(1, SyncOperation::count());
        $this->assertSame('failed', SyncOperation::first()->status);
    }

    // ── Tenancy ──────────────────────────────────────────────────────────

    public function test_another_hospitals_operation_id_is_not_readable(): void
    {
        $theirs = Hospital::factory()->create();
        $theirUser = User::factory()->create(['hospital_id' => $theirs->id, 'role' => 'nurse']);

        $op = $this->operation();

        app(CurrentHospital::class)->set($theirs->id);
        SyncOperation::create([
            'operation_id' => $op['operation_id'],
            'hospital_id' => $theirs->id,
            'user_id' => $theirUser->id,
            'entity' => 'patients',
            'entity_uuid' => $op['entity_uuid'],
            'operation' => 'create',
            'status' => 'accepted',
            'server_id' => 4242,
            'result' => ['status' => 'accepted', 'server_id' => 4242],
        ]);

        app(CurrentHospital::class)->set($this->hospital->id);
        $out = $this->ledger->once($op, fn () => $this->fail('ran for another tenant'));

        // Refused, and the other hospital's server_id is not handed over.
        $this->assertSame('rejected', $out['result']['status']);
        $this->assertSame('unknown_operation', $out['result']['reason_code']);
        $this->assertArrayNotHasKey('server_id', $out['result']);
    }

    // ── Tampering ────────────────────────────────────────────────────────

    public function test_a_different_payload_under_the_same_id_is_flagged_and_the_first_result_stands(): void
    {
        $op = $this->operation();
        $this->ledger->once($op, $this->createPatient($op));

        $tampered = $op;
        $tampered['payload'] = ['first_name' => 'Somebody', 'last_name' => 'Else'];

        $out = $this->ledger->once($tampered, fn () => $this->fail('ran for a tampered replay'));

        $this->assertTrue($out['replayed']);
        $this->assertTrue($out['result']['payload_mismatch']);
        $this->assertSame('Amina', Patient::first()->first_name);
        $this->assertSame(1, Patient::count());
    }

    public function test_the_payload_itself_is_never_stored(): void
    {
        $op = $this->operation(['payload' => ['first_name' => 'Confidential', 'diagnosis' => 'private']]);
        $this->ledger->once($op, fn () => ['status' => 'accepted']);

        $row = DB::table('sync_operations')->first();
        $serialised = json_encode((array) $row);

        // Only a hash — a clinical record in a log table is a second copy
        // under nobody's governance (plan §20).
        $this->assertStringNotContainsString('Confidential', $serialised);
        $this->assertStringNotContainsString('private', $serialised);
        $this->assertSame(64, strlen($row->payload_hash));
    }

    public function test_pruning_drops_results_past_the_replay_window(): void
    {
        SyncOperation::create([
            'operation_id' => 'OLD01', 'hospital_id' => $this->hospital->id, 'user_id' => $this->user->id,
            'entity' => 'patients', 'entity_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'operation' => 'create', 'status' => 'accepted',
        ]);
        DB::table('sync_operations')->where('operation_id', 'OLD01')
            ->update(['created_at' => now()->subDays(OperationLedger::RETENTION_DAYS + 1)]);
        SyncOperation::create([
            'operation_id' => 'NEW01', 'hospital_id' => $this->hospital->id, 'user_id' => $this->user->id,
            'entity' => 'patients', 'entity_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'operation' => 'create', 'status' => 'accepted',
        ]);

        $this->assertSame(1, $this->ledger->prune());
        $this->assertSame(1, SyncOperation::count());
    }
}
