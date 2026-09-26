<?php

namespace Tests\Feature\Offline;

use App\Models\Admission;
use App\Models\Bed;
use App\Models\Device;
use App\Models\Hospital;
use App\Models\MedicationAdministration;
use App\Models\Patient;
use App\Models\SyncConflict;
use App\Models\SyncOperation;
use App\Models\User;
use App\Models\VitalRound;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Services\Sync\OperationLedger;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The protocol faults found while building the second client (the mobile and
 * desktop app). Each test is the fault, stated as the behaviour that replaced
 * it.
 */
class SyncProtocolFixesTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $nurse;

    private User $clerk;

    private Device $device;

    private Device $clerkDevice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->nurse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $this->nurse->syncSpatieRole();
        $this->device = $this->registerDevice($this->nurse);

        $this->clerk = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'receptionist']);
        $this->clerk->syncSpatieRole();
        $this->clerkDevice = $this->registerDevice($this->clerk);

        Sanctum::actingAs($this->nurse);
    }

    private function registerDevice(User $user): Device
    {
        return Device::create([
            'hospital_id' => $this->hospital->id,
            'user_id' => $user->id,
            'device_uuid' => (string) Str::uuid(),
            'label' => 'Ward tablet',
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
            'client_created_at' => now()->toIso8601String(),
        ], $extra);
    }

    private function push(array $operations, ?Device $device = null)
    {
        return $this->withHeader('X-Device-Id', ($device ?? $this->device)->device_uuid)
            ->postJson(route('api.sync.push'), ['operations' => $operations]);
    }

    private function admission(): Admission
    {
        $ward = Ward::factory()->create(['hospital_id' => $this->hospital->id]);
        $bed = Bed::factory()->create(['hospital_id' => $this->hospital->id, 'ward_id' => $ward->id]);
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);

        return app(AdmissionService::class)->admit($patient, $bed, [], $this->nurse->id);
    }

    /** @return array<string,mixed> the same ledger fields SyncEngine passes */
    private function ledgerOp(): array
    {
        return [
            'operation_id' => strtoupper((string) Str::ulid()),
            'entity' => 'patients',
            'entity_uuid' => (string) Str::uuid(),
            'operation' => 'create',
            'payload' => ['first_name' => 'Amina', 'last_name' => 'Nakato'],
            'user_id' => $this->nurse->id,
            'device_id' => $this->device->id,
        ];
    }

    private function writesAPatient(array $op): callable
    {
        return function () use ($op) {
            $patient = Patient::create([
                'uuid' => $op['entity_uuid'], 'hospital_id' => $this->hospital->id,
                'patient_no' => 'PAT-'.substr($op['entity_uuid'], 0, 8),
                'first_name' => 'Amina', 'last_name' => 'Nakato',
            ]);

            return ['status' => 'accepted', 'server_id' => $patient->id, 'version' => 1];
        };
    }

    // ── S1 · a failure that applied nothing is not final ─────────────────

    /**
     * One transient 500 used to poison the operation id for ever: every retry
     * was handed the stored "failed" back, and the record never reached the
     * server.
     */
    public function test_an_operation_that_failed_on_the_server_runs_again_when_retried(): void
    {
        $ledger = app(OperationLedger::class);
        $op = $this->ledgerOp();

        $first = $ledger->once($op, fn () => throw new \RuntimeException('database went away'));
        $this->assertSame('failed', $first['result']['status']);
        $this->assertSame('server_error', $first['result']['reason_code']);

        $retry = $ledger->once($op, $this->writesAPatient($op));

        $this->assertFalse($retry['replayed'], 'the retry was handed the stored failure instead of running');
        $this->assertSame('accepted', $retry['result']['status']);
        $this->assertSame(1, Patient::count());
        $this->assertSame('accepted', SyncOperation::sole()->status);

        // …and from here on it is an ordinary settled operation: replayed, not re-run.
        $again = $ledger->once($op, fn () => $this->fail('an accepted operation ran twice'));
        $this->assertTrue($again['replayed']);
        $this->assertSame(1, Patient::count());
    }

    /**
     * Two retries of the same failed operation arriving together: exactly one
     * runs. Simulated by replaying from INSIDE the first retry's work, which is
     * the moment a concurrent request would arrive.
     */
    public function test_two_retries_arriving_together_run_the_work_once(): void
    {
        $ledger = app(OperationLedger::class);
        $op = $this->ledgerOp();
        $ledger->once($op, fn () => throw new \RuntimeException('first attempt failed'));

        $inner = null;
        $ledger->once($op, function () use ($ledger, $op, &$inner) {
            $inner = $ledger->once($op, fn () => $this->fail('the concurrent retry ran the work as well'));

            return ($this->writesAPatient($op))();
        });

        $this->assertSame('failed', $inner['result']['status']);
        $this->assertSame('in_flight', $inner['result']['reason_code']);
        $this->assertSame(1, Patient::count());
    }

    public function test_an_abandoned_claim_is_taken_over_by_one_retry_only(): void
    {
        $ledger = app(OperationLedger::class);
        $op = $this->ledgerOp();

        SyncOperation::create([
            'operation_id' => $op['operation_id'], 'hospital_id' => $this->hospital->id,
            'user_id' => $this->nurse->id, 'entity' => 'patients', 'entity_uuid' => $op['entity_uuid'],
            'operation' => 'create', 'status' => 'processing',
        ]);
        DB::table('sync_operations')->update(['created_at' => now()->subHour()]);

        $inner = null;
        $out = $ledger->once($op, function () use ($ledger, $op, &$inner) {
            $inner = $ledger->once($op, fn () => $this->fail('two takeovers of one abandoned claim'));

            return ($this->writesAPatient($op))();
        });

        $this->assertSame('accepted', $out['result']['status']);
        $this->assertSame('in_flight', $inner['result']['reason_code']);
        $this->assertSame(1, Patient::count());
    }

    public function test_a_claim_marked_abandoned_by_the_sweep_is_retryable(): void
    {
        $ledger = app(OperationLedger::class);
        $op = $this->ledgerOp();

        SyncOperation::create([
            'operation_id' => $op['operation_id'], 'hospital_id' => $this->hospital->id,
            'user_id' => $this->nurse->id, 'entity' => 'patients', 'entity_uuid' => $op['entity_uuid'],
            'operation' => 'create', 'status' => 'processing',
        ]);
        DB::table('sync_operations')->update(['created_at' => now()->subHour()]);
        $this->assertSame(1, $ledger->reclaimStale());

        $retry = $ledger->once($op, $this->writesAPatient($op));

        $this->assertSame('accepted', $retry['result']['status']);
        $this->assertSame(1, Patient::count());
    }

    /**
     * A bedside record sent before its admission had reached the server was
     * refused as `parent_missing` — and, under the same id, refused for ever.
     */
    public function test_a_record_refused_for_a_missing_parent_is_accepted_once_the_parent_exists(): void
    {
        $admission = $this->admission();
        $realUuid = $admission->uuid;
        $admission->forceFill(['uuid' => (string) Str::uuid()])->saveQuietly(); // not "arrived" yet

        $vitals = $this->op('vitals', 'create', ['uuid' => (string) Str::uuid(), 'admission_uuid' => $realUuid, 'pulse' => 72]);

        $this->assertSame('parent_missing', $this->push([$vitals])->json('data.results.0.reason_code'));

        $admission->forceFill(['uuid' => $realUuid])->saveQuietly(); // now it has

        $this->assertSame('accepted', $this->push([$vitals])->json('data.results.0.status'));
        $this->assertSame(1, VitalRound::count());
    }

    /** Answers about the DATA stay final: a replay still gets them verbatim. */
    public function test_a_validation_refusal_is_still_replayed_not_rerun(): void
    {
        $admission = $this->admission();
        $bad = $this->op('vitals', 'create', ['uuid' => (string) Str::uuid(), 'admission_uuid' => $admission->uuid, 'pulse' => 900]);

        $this->assertSame('validation_failed', $this->push([$bad])->json('data.results.0.reason_code'));
        $this->assertSame('validation_failed', $this->push([$bad])->json('data.results.0.reason_code'));
        $this->assertSame(1, SyncOperation::count());
    }

    // ── S2 · medication status ───────────────────────────────────────────

    public function test_a_medication_status_the_server_does_not_have_is_refused_not_crashed(): void
    {
        $admission = $this->admission();

        foreach (['held', 'missed', 'Given'] as $status) {
            $result = $this->push([$this->op('med_administrations', 'create', [
                'uuid' => (string) Str::uuid(), 'admission_uuid' => $admission->uuid,
                'drug_name' => 'Ceftriaxone', 'status' => $status,
            ])])->json('data.results.0');

            $this->assertSame('rejected', $result['status'], "{$status} was not refused");
            $this->assertSame('validation_failed', $result['reason_code'], "{$status} crashed instead of being refused");
            $this->assertArrayHasKey('status', $result['errors']);
        }

        $this->assertSame(0, MedicationAdministration::count());
    }

    public function test_every_status_the_web_offers_is_accepted(): void
    {
        $admission = $this->admission();

        foreach (['given', 'withheld', 'refused'] as $status) {
            $this->assertSame('accepted', $this->push([$this->op('med_administrations', 'create', [
                'uuid' => (string) Str::uuid(), 'admission_uuid' => $admission->uuid,
                'drug_name' => 'Ceftriaxone', 'status' => $status,
            ])])->json('data.results.0.status'), "{$status} was refused");
        }
    }

    // ── S3 · a device is required to write or to read the ledger ─────────

    public function test_pushing_without_a_device_is_refused(): void
    {
        $this->postJson(route('api.sync.push'), ['operations' => [
            $this->op('nursing_notes', 'create', ['uuid' => (string) Str::uuid(), 'admission_uuid' => $this->admission()->uuid, 'note' => 'x']),
        ]])->assertStatus(403)->assertJsonPath('code', 'forbidden');

        $this->assertSame(0, SyncOperation::count());
    }

    public function test_the_ledger_is_only_readable_by_a_device_and_only_its_own(): void
    {
        $admission = $this->admission();
        $this->push([$this->op('nursing_notes', 'create', ['uuid' => (string) Str::uuid(), 'admission_uuid' => $admission->uuid, 'note' => 'mine'])]);

        // Laravel's test client keeps headers between requests; this one has none.
        $this->flushHeaders();
        $this->getJson(route('api.sync.operations'))->assertStatus(403);

        Sanctum::actingAs($this->clerk);
        $this->withHeader('X-Device-Id', $this->clerkDevice->device_uuid)
            ->getJson(route('api.sync.operations'))
            ->assertOk()
            ->assertJsonCount(0, 'data.operations');
    }

    // ── S5 · conflicts are closed ────────────────────────────────────────

    /** @return array{0:string, 1:array<string,mixed>} a patient in conflict with the clerk's device */
    private function aConflict(): array
    {
        Sanctum::actingAs($this->clerk);
        $uuid = (string) Str::uuid();
        $this->push([$this->op('patients', 'create', [
            'uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato', 'dob' => '1990-01-01',
        ])], $this->clerkDevice)->assertOk();

        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        $patient->update(['dob' => '1991-05-05']);
        $patient->forceFill(['version' => 2])->saveQuietly();

        $op = $this->op('patients', 'update', [
            'uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato', 'dob' => '1992-09-09',
        ], ['base_version' => 1, 'base_fields' => ['dob' => '1990-01-01']]);

        $this->assertSame('conflict', $this->push([$op], $this->clerkDevice)->json('data.results.0.status'));

        return [$uuid, $op];
    }

    public function test_replaying_a_conflicted_operation_does_not_record_it_twice(): void
    {
        [, $op] = $this->aConflict();

        $this->push([$op], $this->clerkDevice);
        $this->push([$op], $this->clerkDevice);

        $this->assertSame(1, SyncConflict::count());
    }

    public function test_keeping_mine_closes_the_conflict_when_the_server_accepts_it(): void
    {
        [$uuid] = $this->aConflict();

        $this->push([$this->op('patients', 'update', [
            'uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato', 'dob' => '1992-09-09',
        ], ['base_version' => 2, 'base_fields' => ['dob' => '1991-05-05']])], $this->clerkDevice)
            ->assertJsonPath('data.results.0.status', 'accepted');

        $conflict = SyncConflict::sole();
        $this->assertNotNull($conflict->resolved_at);
        $this->assertSame('kept_mine', $conflict->resolution);
    }

    public function test_keeping_the_servers_is_reported_and_closes_it(): void
    {
        [, $op] = $this->aConflict();

        $this->withHeader('X-Device-Id', $this->clerkDevice->device_uuid)
            ->getJson(route('api.sync.status'))->assertJsonPath('data.open_conflicts', 1);

        $this->withHeader('X-Device-Id', $this->clerkDevice->device_uuid)
            ->postJson(route('api.sync.resolve'), ['operation_id' => $op['operation_id'], 'resolution' => 'kept_server'])
            ->assertOk()->assertJsonPath('data.closed', 1);

        $this->assertSame('kept_server', SyncConflict::sole()->resolution);
        $this->withHeader('X-Device-Id', $this->clerkDevice->device_uuid)
            ->getJson(route('api.sync.status'))->assertJsonPath('data.open_conflicts', 0);
    }

    public function test_a_device_sees_only_its_own_open_conflicts_and_cannot_close_anothers(): void
    {
        [, $op] = $this->aConflict();

        Sanctum::actingAs($this->nurse);
        $this->withHeader('X-Device-Id', $this->device->device_uuid)
            ->getJson(route('api.sync.status'))->assertJsonPath('data.open_conflicts', 0);

        $this->withHeader('X-Device-Id', $this->device->device_uuid)
            ->postJson(route('api.sync.resolve'), ['operation_id' => $op['operation_id'], 'resolution' => 'kept_server'])
            ->assertOk()->assertJsonPath('data.closed', 0);

        $this->assertNull(SyncConflict::sole()->resolved_at);
    }

    // ── Online edits advance the version ─────────────────────────────────

    /**
     * Found by the mobile app's end-to-end test: a date of birth corrected
     * ONLINE was silently overwritten by a device's older edit, because only
     * sync writes moved `version` and the device's base looked current.
     */
    public function test_an_online_correction_is_not_overwritten_by_an_older_device_edit(): void
    {
        Sanctum::actingAs($this->clerk);
        $uuid = (string) Str::uuid();
        $this->push([$this->op('patients', 'create', ['uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato', 'dob' => '1990-01-01'])], $this->clerkDevice);
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        $this->assertSame(1, (int) $patient->version);

        // Somebody at the desk corrects the date of birth in the web panel.
        app(\App\Services\PatientService::class)->update($patient, ['first_name' => 'Amina', 'last_name' => 'Nakato', 'dob' => '1985-05-05']);
        $this->assertSame(2, (int) $patient->fresh()->version, 'an online edit did not advance the version');

        // The device, which last saw version 1, sends its own change.
        $result = $this->push([$this->op('patients', 'update', [
            'uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato', 'dob' => '1992-02-02',
        ], ['base_version' => 1, 'base_fields' => ['dob' => '1990-01-01', 'first_name' => 'Amina', 'last_name' => 'Nakato']])], $this->clerkDevice)->json('data.results.0');

        $this->assertSame('conflict', $result['status']);
        $this->assertContains('dob', $result['conflict']['contested']);
        $this->assertSame('1985-05-05', $patient->fresh()->dob->format('Y-m-d'), 'the online correction was overwritten');
    }

    public function test_a_save_that_changes_nothing_does_not_advance_the_version(): void
    {
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $version = (int) $patient->fresh()->version;

        $patient->fresh()->save();
        $patient->fresh()->touch();

        $this->assertSame($version, (int) $patient->fresh()->version);
    }

    public function test_a_sync_write_is_counted_once_not_twice(): void
    {
        Sanctum::actingAs($this->clerk);
        $uuid = (string) Str::uuid();
        $this->push([$this->op('patients', 'create', ['uuid' => $uuid, 'first_name' => 'A', 'last_name' => 'B'])], $this->clerkDevice);

        $version = $this->push([$this->op('patients', 'update', ['uuid' => $uuid, 'first_name' => 'A', 'last_name' => 'C'], ['base_version' => 1, 'base_fields' => ['last_name' => 'B']])], $this->clerkDevice)->json('data.results.0.version');

        $this->assertSame(2, $version);
        $this->assertSame(2, (int) Patient::where('uuid', $uuid)->value('version'));
    }

    /**
     * Found by the mobile app's end-to-end test: merging an edit into a
     * patient whose sex is recorded threw (an enum compared as a string), so
     * the edit failed with a server error instead of merging or conflicting.
     */
    public function test_a_patient_with_a_recorded_sex_can_be_merged(): void
    {
        Sanctum::actingAs($this->clerk);
        $uuid = (string) Str::uuid();
        $this->push([$this->op('patients', 'create', ['uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato', 'sex' => 'female', 'phone_1' => '0700'])], $this->clerkDevice);
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        app(\App\Services\PatientService::class)->update($patient, ['first_name' => 'Amina', 'last_name' => 'Nakato', 'address' => 'Kampala']);

        $payload = ['uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato', 'sex' => 'female', 'status' => 'active', 'phone_1' => '0772'];
        $result = $this->push([$this->op('patients', 'update', $payload, [
            'base_version' => 1,
            'base_fields' => ['first_name' => 'Amina', 'last_name' => 'Nakato', 'sex' => 'female', 'status' => 'active', 'phone_1' => '0700'],
        ])], $this->clerkDevice)->json('data.results.0');

        $this->assertSame('accepted', $result['status'], json_encode($result));
        $this->assertSame('0772', $patient->fresh()->phone_1, "the device's change was merged");
        $this->assertSame('Kampala', $patient->fresh()->address, "the online change was kept");
    }

    // ── A device holds the whole editable record ─────────────────────────

    /** Every field a device may edit is pulled, so an edit to it has a base. */
    public function test_a_pulled_patient_carries_every_field_a_device_may_edit(): void
    {
        Sanctum::actingAs($this->clerk);
        Patient::factory()->create(['hospital_id' => $this->hospital->id, 'email' => 'amina@example.test', 'mother_name' => 'Sarah', 'insurance_provider' => 'Jubilee']);

        $record = collect($this->withHeader('X-Device-Id', $this->clerkDevice->device_uuid)->getJson(route('api.sync.pull'))->json('data.changes'))
            ->firstWhere('entity', 'patients')['record'];

        $mergeable = (new \ReflectionClassConstant(\App\Services\Sync\Handlers\PatientHandler::class, 'MERGEABLE'))->getValue();
        foreach ($mergeable as $field) {
            $this->assertArrayHasKey($field, $record, "{$field} is editable on a device but never pulled, so it has no merge base");
        }
        $this->assertSame('amina@example.test', $record['email']);
        $this->assertSame('Jubilee', $record['insurance_provider']);
        $this->assertArrayNotHasKey('bank_details', $record);
    }

    public function test_insurance_and_consent_captured_on_a_device_are_kept(): void
    {
        Sanctum::actingAs($this->clerk);
        $uuid = (string) Str::uuid();

        $this->push([$this->op('patients', 'create', [
            'uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato',
            'insurance_provider' => 'Jubilee', 'insurance_member_no' => 'JB-001', 'consent_given' => true,
        ])], $this->clerkDevice)->assertJsonPath('data.results.0.status', 'accepted');

        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        $this->assertSame('Jubilee', $patient->insurance_provider);
        $this->assertSame('JB-001', $patient->insurance_member_no);
        $this->assertTrue($patient->consent_given);
        $this->assertNotNull($patient->consent_at);
    }
}
