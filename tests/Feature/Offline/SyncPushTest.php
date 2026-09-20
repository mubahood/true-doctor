<?php

namespace Tests\Feature\Offline;

use App\Models\Admission;
use App\Models\Bed;
use App\Models\Device;
use App\Models\Hospital;
use App\Models\NursingNote;
use App\Models\Patient;
use App\Models\SyncConflict;
use App\Models\SyncOperation;
use App\Models\User;
use App\Models\Visit;
use App\Models\VitalRound;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Push, over HTTP, the way a device actually does it.
 *
 * `OperationLedgerTest` proves the gate in isolation. This proves the whole
 * path: a real batch, real Policies, real Services, real tenancy — and the
 * failure modes that only exist once a network is involved.
 */
class SyncPushTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $nurse;

    /** Registration is reception's job, not nursing's — the RBAC matrix says so. */
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

    private function registerDevice(User $user, ?Hospital $hospital = null): Device
    {
        return Device::create([
            'hospital_id' => ($hospital ?? $this->hospital)->id,
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

    /** Push as the desk clerk, who is the role allowed to register patients. */
    private function pushAsClerk(array $operations)
    {
        Sanctum::actingAs($this->clerk);

        return $this->push($operations, $this->clerkDevice);
    }

    // ── The happy path ───────────────────────────────────────────────────

    public function test_a_patient_captured_offline_arrives_with_a_server_number(): void
    {
        $uuid = (string) Str::uuid();

        $response = $this->pushAsClerk([
            $this->op('patients', 'create', ['uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato']),
        ])->assertOk();

        $result = $response->json('data.results.0');

        $this->assertSame('accepted', $result['status']);
        $this->assertNotNull($result['server_id']);

        dump(['want' => $uuid, 'rows' => \Illuminate\Support\Facades\DB::table('patients')->get(['id', 'uuid', 'hospital_id'])->toArray(), 'ch' => app(CurrentHospital::class)->id()]);
        $patient = Patient::where('uuid', $uuid)->firstOrFail();

        // The device's id survived; the number came from the server's Sequence
        // (invariants I-8 and I-11).
        $this->assertSame($uuid, $patient->uuid);
        $this->assertNotEmpty($patient->patient_no);
        $this->assertSame($patient->patient_no, $result['assigned']['patient_no']);
        $this->assertSame(1, (int) $patient->version);
        $this->assertNotNull($patient->sync_revision);
        $this->assertSame($this->clerkDevice->id, $patient->origin_device_id);
    }

    public function test_a_batch_of_forty_is_applied_in_one_request(): void
    {
        $ops = [];
        for ($i = 0; $i < 40; $i++) {
            $ops[] = $this->op('patients', 'create', [
                'uuid' => (string) Str::uuid(), 'first_name' => "Patient{$i}", 'last_name' => 'Test',
            ]);
        }

        $results = $this->pushAsClerk($ops)->assertOk()->json('data.results');

        $this->assertCount(40, $results);
        $this->assertSame(40, collect($results)->where('status', 'accepted')->count());
        $this->assertSame(40, Patient::count());
    }

    /** One bad operation must not roll back the good ones (plan §9.5). */
    public function test_one_refusal_does_not_take_the_rest_of_the_batch_with_it(): void
    {
        $results = $this->pushAsClerk([
            $this->op('patients', 'create', ['uuid' => (string) Str::uuid(), 'first_name' => 'Good', 'last_name' => 'One']),
            // No last_name — the shared PatientRequest rules refuse it.
            $this->op('patients', 'create', ['uuid' => (string) Str::uuid(), 'first_name' => 'Bad']),
            $this->op('patients', 'create', ['uuid' => (string) Str::uuid(), 'first_name' => 'Good', 'last_name' => 'Two']),
        ])->assertOk()->json('data.results');

        $this->assertSame('accepted', $results[0]['status']);
        $this->assertSame('rejected', $results[1]['status']);
        $this->assertSame('validation_failed', $results[1]['reason_code']);
        $this->assertArrayHasKey('last_name', $results[1]['errors']);
        $this->assertSame('accepted', $results[2]['status']);

        $this->assertSame(2, Patient::count());
    }

    // ── Scenario 8 · the server commits and the reply is lost ────────────

    public function test_replaying_a_whole_batch_creates_nothing_a_second_time(): void
    {
        $ops = [
            $this->op('patients', 'create', ['uuid' => (string) Str::uuid(), 'first_name' => 'Amina', 'last_name' => 'Nakato']),
            $this->op('patients', 'create', ['uuid' => (string) Str::uuid(), 'first_name' => 'Joseph', 'last_name' => 'Okello']),
        ];

        $this->pushAsClerk($ops)->assertOk();
        $this->assertSame(2, Patient::count());

        // …the response never reached the browser, so it sends the same batch.
        $replay = $this->pushAsClerk($ops)->assertOk()->json('data.results');

        $this->assertSame('already_processed', $replay[0]['status']);
        $this->assertSame('already_processed', $replay[1]['status']);

        // Still two. This is the assertion the whole design exists for.
        $this->assertSame(2, Patient::count());
        $this->assertSame(2, SyncOperation::count());
    }

    /**
     * A subtler duplicate: the device got "accepted", lost the reply, and then
     * re-queued the work under a NEW operation id. The ledger cannot see that
     * — the uuid is the second net (invariant I-8).
     */
    public function test_the_same_record_under_a_new_operation_id_is_not_duplicated(): void
    {
        $uuid = (string) Str::uuid();
        $payload = ['uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato'];

        $first = $this->pushAsClerk([$this->op('patients', 'create', $payload)])->json('data.results.0');
        $second = $this->pushAsClerk([$this->op('patients', 'create', $payload)])->json('data.results.0');

        $this->assertSame('accepted', $second['status']);
        $this->assertSame($first['server_id'], $second['server_id']);
        $this->assertSame(1, Patient::count());
    }

    public function test_the_same_vitals_round_under_a_new_operation_id_is_not_duplicated(): void
    {
        $admission = $this->admission();
        $uuid = (string) Str::uuid();
        $payload = ['uuid' => $uuid, 'admission_uuid' => $admission->uuid, 'pulse' => 82, 'temperature' => 36.8];

        $this->push([$this->op('vitals', 'create', $payload)])->assertOk();
        $this->push([$this->op('vitals', 'create', $payload)])->assertOk();

        $this->assertSame(1, VitalRound::count());
    }

    // ── Append-only clinical records ─────────────────────────────────────

    public function test_vitals_recorded_at_the_bedside_arrive_with_their_provenance(): void
    {
        $admission = $this->admission();
        $clientTime = now()->subMinutes(40)->toIso8601String();

        $this->push([
            $this->op('vitals', 'create', [
                'uuid' => (string) Str::uuid(), 'admission_uuid' => $admission->uuid,
                'pulse' => 78, 'temperature' => 37.1, 'spo2' => 97,
            ], ['client_created_at' => $clientTime]),
        ])->assertOk();

        $round = VitalRound::firstOrFail();

        $this->assertSame(78, $round->pulse);
        $this->assertSame($this->nurse->id, $round->recorded_by);
        $this->assertSame($this->device->id, $round->origin_device_id);
        // The device's clock is kept as provenance; the row's own created_at is
        // the server's, which is authority (plan §7.3).
        $this->assertNotNull($round->client_created_at);
        $this->assertTrue($round->created_at->gt($round->client_created_at));
    }

    public function test_two_devices_recording_two_rounds_recorded_two_rounds(): void
    {
        $admission = $this->admission();

        $this->push([$this->op('vitals', 'create', ['uuid' => (string) Str::uuid(), 'admission_uuid' => $admission->uuid, 'pulse' => 70])]);
        $this->push([$this->op('vitals', 'create', ['uuid' => (string) Str::uuid(), 'admission_uuid' => $admission->uuid, 'pulse' => 90])]);

        // Append-only: nothing to merge, nothing overwritten. Both are true.
        $this->assertSame(2, VitalRound::count());
    }

    public function test_a_bedside_record_cannot_be_edited_only_superseded(): void
    {
        $admission = $this->admission();
        $uuid = (string) Str::uuid();

        $this->push([$this->op('vitals', 'create', ['uuid' => $uuid, 'admission_uuid' => $admission->uuid, 'pulse' => 70])]);

        $result = $this->push([
            $this->op('vitals', 'update', ['uuid' => $uuid, 'admission_uuid' => $admission->uuid, 'pulse' => 90]),
        ])->json('data.results.0');

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('append_only', $result['reason_code']);
        $this->assertSame(70, VitalRound::first()->pulse);
    }

    public function test_a_record_whose_parent_has_not_arrived_says_so_plainly(): void
    {
        $result = $this->push([
            $this->op('vitals', 'create', [
                'uuid' => (string) Str::uuid(), 'admission_uuid' => (string) Str::uuid(), 'pulse' => 70,
            ]),
        ])->json('data.results.0');

        // An ordering slip, not bad data — the message has to let the device
        // retry rather than show a clinician a message about a missing visit.
        $this->assertSame('rejected', $result['status']);
        $this->assertSame('parent_missing', $result['reason_code']);
        $this->assertSame(0, VitalRound::count());
    }

    public function test_nothing_new_can_be_recorded_on_a_closed_admission(): void
    {
        $admission = $this->admission();
        app(AdmissionService::class)->discharge($admission, \App\Enums\AdmissionStatus::Discharged, null, $this->nurse->id);

        $result = $this->push([
            $this->op('nursing_notes', 'create', [
                'uuid' => (string) Str::uuid(), 'admission_uuid' => $admission->uuid, 'note' => 'Late note',
            ]),
        ])->json('data.results.0');

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('parent_closed', $result['reason_code']);
        $this->assertSame(0, NursingNote::count());
    }

    public function test_a_vitals_reading_outside_human_range_is_refused(): void
    {
        $admission = $this->admission();

        $result = $this->push([
            $this->op('vitals', 'create', ['uuid' => (string) Str::uuid(), 'admission_uuid' => $admission->uuid, 'pulse' => 9000]),
        ])->json('data.results.0');

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('validation_failed', $result['reason_code']);
    }

    // ── Authorisation, per operation ─────────────────────────────────────

    public function test_a_permission_lost_while_offline_refuses_the_work_now(): void
    {
        $admission = $this->admission();

        // The nurse was moved to reception while the tablet was in a drawer.
        $this->nurse->update(['role' => 'receptionist']);
        $this->nurse->syncSpatieRole();
        $this->nurse->refresh();
        Sanctum::actingAs($this->nurse->fresh());

        $result = $this->push([
            $this->op('vitals', 'create', ['uuid' => (string) Str::uuid(), 'admission_uuid' => $admission->uuid, 'pulse' => 70]),
        ])->json('data.results.0');

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('forbidden', $result['reason_code']);
        $this->assertSame(0, VitalRound::count());
    }

    public function test_authorisation_is_checked_on_every_operation_not_once_per_batch(): void
    {
        $admission = $this->admission();
        Sanctum::actingAs($this->clerk);

        $results = $this->push([
            // Reception may register a patient…
            $this->op('patients', 'create', ['uuid' => (string) Str::uuid(), 'first_name' => 'Amina', 'last_name' => 'Nakato']),
            // …but not record vitals. Both are in the same batch.
            $this->op('vitals', 'create', ['uuid' => (string) Str::uuid(), 'admission_uuid' => $admission->uuid, 'pulse' => 70]),
        ], $this->clerkDevice)->json('data.results');

        $this->assertSame('accepted', $results[0]['status']);
        $this->assertSame('rejected', $results[1]['status']);
        $this->assertSame('forbidden', $results[1]['reason_code']);
    }

    // ── Devices ──────────────────────────────────────────────────────────

    public function test_an_unregistered_device_cannot_push(): void
    {
        $this->withHeader('X-Device-Id', (string) Str::uuid())
            ->postJson(route('api.sync.push'), ['operations' => [
                $this->op('patients', 'create', ['uuid' => (string) Str::uuid(), 'first_name' => 'A', 'last_name' => 'B']),
            ]])
            ->assertForbidden();

        $this->assertSame(0, Patient::count());
    }

    public function test_a_revoked_device_is_told_to_clear_itself(): void
    {
        $this->device->update(['revoked_at' => now(), 'revoked_reason' => 'Laptop was lost.']);

        $response = $this->push([
            $this->op('patients', 'create', ['uuid' => (string) Str::uuid(), 'first_name' => 'A', 'last_name' => 'B']),
        ])->assertForbidden();

        // The code is what the client keys its local wipe on, so it has to be
        // unambiguous and reserved for this.
        $this->assertSame('device_revoked', $response->json('code'));
        $this->assertSame('Laptop was lost.', $response->json('message'));
        $this->assertSame(0, Patient::count());
    }

    public function test_a_device_on_another_protocol_is_turned_away_before_any_work(): void
    {
        $response = $this->withHeader('X-Device-Id', $this->device->device_uuid)
            ->postJson(route('api.sync.push'), [
                'protocol_version' => 99,
                'operations' => [$this->op('patients', 'create', ['uuid' => (string) Str::uuid(), 'first_name' => 'A', 'last_name' => 'B'])],
            ])
            ->assertStatus(409);

        $this->assertSame('protocol_mismatch', $response->json('code'));
        $this->assertSame(0, Patient::count());
    }

    // ── Tenancy ──────────────────────────────────────────────────────────

    public function test_a_device_cannot_write_into_another_hospital(): void
    {
        $theirs = Hospital::factory()->create();

        Sanctum::actingAs($this->clerk);

        $this->withHeader('X-Device-Id', $this->clerkDevice->device_uuid)
            ->postJson(route('api.sync.push'), [
                'operations' => [
                    // The payload claims another tenant. The server takes the
                    // hospital from the TOKEN, never from what was sent.
                    $this->op('patients', 'create', [
                        'uuid' => (string) Str::uuid(), 'first_name' => 'A', 'last_name' => 'B',
                        'hospital_id' => $theirs->id,
                    ]),
                ],
            ])->assertOk();

        $this->assertSame($this->hospital->id, Patient::firstOrFail()->hospital_id);
        $this->assertSame(0, Patient::withoutGlobalScopes()->where('hospital_id', $theirs->id)->count());
    }

    public function test_another_hospitals_device_id_is_not_usable(): void
    {
        $theirs = Hospital::factory()->create();
        $theirUser = User::factory()->create(['hospital_id' => $theirs->id, 'role' => 'nurse']);
        $theirUser->syncSpatieRole();
        $theirDevice = $this->registerDevice($theirUser, $theirs);

        // Our nurse's token, their device id.
        $this->push([
            $this->op('patients', 'create', ['uuid' => (string) Str::uuid(), 'first_name' => 'A', 'last_name' => 'B']),
        ], $theirDevice)->assertForbidden();

        $this->assertSame(0, Patient::count());
    }

    // ── Batch limits and malformed input ─────────────────────────────────

    public function test_an_oversized_batch_is_refused_rather_than_truncated(): void
    {
        $ops = [];
        for ($i = 0; $i < 250; $i++) {
            $ops[] = $this->op('patients', 'create', ['uuid' => (string) Str::uuid(), 'first_name' => "P{$i}", 'last_name' => 'T']);
        }

        // Truncating would leave the device believing work was sent that was not.
        $this->pushAsClerk($ops)->assertStatus(422);
        $this->assertSame(0, Patient::count());
    }

    public function test_an_operation_with_no_id_fails_validation_for_the_whole_request(): void
    {
        $bad = $this->op('patients', 'create', ['uuid' => (string) Str::uuid(), 'first_name' => 'A', 'last_name' => 'B']);
        unset($bad['operation_id']);

        $this->push([$bad])->assertStatus(422);
        $this->assertSame(0, Patient::count());
    }

    public function test_an_entity_this_server_does_not_know_is_reported_not_crashed(): void
    {
        $result = $this->push([
            $this->op('prescriptions', 'create', ['uuid' => (string) Str::uuid()]),
        ])->assertOk()->json('data.results.0');

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('unknown_entity', $result['reason_code']);
    }

    // ── Conflicts ────────────────────────────────────────────────────────

    public function test_an_edit_against_a_stale_version_is_merged_where_it_is_safe(): void
    {
        $uuid = (string) Str::uuid();
        $this->pushAsClerk([$this->op('patients', 'create', [
            'uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato', 'phone_1' => '0700',
        ])])->assertOk();

        // Somebody at reception changes the address online.
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        $patient->update(['address' => 'Kololo']);
        $patient->forceFill(['version' => 2])->saveQuietly();

        // The device, still on version 1, changes the phone.
        $result = $this->pushAsClerk([
            $this->op('patients', 'update', [
                'uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato', 'phone_1' => '0711',
            ], [
                'base_version' => 1,
                // What the device last had confirmed for the field it changed.
                'base_fields' => ['phone_1' => '0700'],
            ]),
        ])->json('data.results.0');

        $patient->refresh();

        // Different fields, so both survive — losing either would be losing
        // somebody's actual work (plan §10.1).
        $this->assertSame('0711', $patient->phone_1);
        $this->assertSame('Kololo', $patient->address);
        $this->assertContains($result['status'], ['accepted', 'conflict']);
    }

    /**
     * A record created and edited before its first sync.
     *
     * `base_version` 0 means the device has never had ANY version confirmed,
     * which happens only when it created the record itself. The only writer is
     * that device, so there is nothing to merge against — treating it as a
     * conflict produced one for a record nobody else had touched.
     */
    public function test_editing_a_record_before_its_first_sync_is_not_a_conflict(): void
    {
        $uuid = (string) Str::uuid();

        // Create and edit arrive together, the way an outbox sends them.
        $results = $this->pushAsClerk([
            $this->op('patients', 'create', ['uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato', 'phone_1' => '0700']),
            $this->op('patients', 'update', ['uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato', 'phone_1' => '0711'],
                ['base_version' => 0, 'base_fields' => []]),
        ])->assertOk()->json('data.results');

        $this->assertSame('accepted', $results[0]['status']);
        $this->assertSame('accepted', $results[1]['status']);
        $this->assertSame('0711', Patient::where('uuid', $uuid)->firstOrFail()->phone_1);
        $this->assertSame(0, SyncConflict::count());
    }

    public function test_a_disagreement_about_a_date_of_birth_is_never_merged_silently(): void
    {
        $uuid = (string) Str::uuid();
        $this->pushAsClerk([$this->op('patients', 'create', [
            'uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato', 'dob' => '1990-01-01',
        ])])->assertOk();

        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        $patient->update(['dob' => '1991-05-05']);
        $patient->forceFill(['version' => 2])->saveQuietly();

        $result = $this->pushAsClerk([
            $this->op('patients', 'update', [
                'uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato', 'dob' => '1992-09-09',
            ], ['base_version' => 1, 'base_fields' => ['dob' => '1990-01-01']]),
        ])->json('data.results.0');

        // A wrong date of birth changes drug dosing. A person decides.
        $this->assertSame('conflict', $result['status']);
        $this->assertSame('manual', $result['conflict']['strategy']);
        $this->assertContains('dob', $result['conflict']['contested']);

        $this->assertSame('1991-05-05', $patient->fresh()->dob->format('Y-m-d'));

        $conflict = SyncConflict::firstOrFail();
        $this->assertSame('patients', $conflict->entity);
        $this->assertSame(['dob'], $conflict->contested_fields);
    }

    public function test_a_conflict_record_holds_field_names_not_clinical_values(): void
    {
        $uuid = (string) Str::uuid();
        $this->pushAsClerk([$this->op('patients', 'create', [
            'uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato', 'allergies' => ['Penicillin'],
        ])])->assertOk();

        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        $patient->update(['allergies' => ['Sulfa']]);
        $patient->forceFill(['version' => 2])->saveQuietly();

        $this->pushAsClerk([
            $this->op('patients', 'update', [
                'uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato', 'allergies' => ['Aspirin'],
            ], ['base_version' => 1, 'base_fields' => ['allergies' => ['Penicillin']]]),
        ])->assertOk();

        $stored = json_encode(SyncConflict::firstOrFail()->toArray());

        $this->assertStringContainsString('allergies', $stored);
        // Names, not values — a clinical value in a conflict row is a second
        // copy of the record under nobody's governance (plan §20).
        $this->assertStringNotContainsString('Aspirin', $stored);
        $this->assertStringNotContainsString('Sulfa', $stored);
    }

    /**
     * The whole conflict cycle, end to end.
     *
     * Detecting a conflict is half a feature. This proves the other half: that
     * the operation the client builds when somebody chooses "mine is right" is
     * one the server actually accepts, rather than one it contests again for
     * exactly the same reason.
     */
    public function test_a_conflict_resolved_as_mine_is_accepted_on_the_second_attempt(): void
    {
        $uuid = (string) Str::uuid();
        $this->pushAsClerk([$this->op('patients', 'create', [
            'uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato', 'dob' => '1990-01-01',
        ])])->assertOk();

        // Somebody at another desk corrects the date of birth online.
        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        $patient->update(['dob' => '1991-05-05']);
        $patient->forceFill(['version' => 2])->saveQuietly();

        // The device, still on version 1, disagrees. Never merged silently.
        $conflict = $this->pushAsClerk([$this->op('patients', 'update', [
            'uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato', 'dob' => '1992-09-09',
        ], ['base_version' => 1, 'base_fields' => ['dob' => '1990-01-01']])])->json('data.results.0');

        $this->assertSame('conflict', $conflict['status']);
        $this->assertSame(2, $conflict['conflict']['server_version']);
        $this->assertSame(['dob' => '1991-05-05'], $conflict['conflict']['server_state']);
        $this->assertSame('1991-05-05', $patient->fresh()->dob->format('Y-m-d'));

        // A person chooses "mine is right". The client re-sends the contested
        // field ALONE, against the version the server told it about, with the
        // server's own value as the base — which is exactly what
        // ConflictResolver::keepMine builds.
        $resolved = $this->pushAsClerk([$this->op('patients', 'update', [
            'uuid' => $uuid, 'dob' => '1992-09-09',
        ], [
            'base_version' => $conflict['conflict']['server_version'],
            'base_fields' => $conflict['conflict']['server_state'],
        ])])->json('data.results.0');

        // Base matches what the server holds, so the merge sees "nobody has
        // moved this since" and applies it.
        $this->assertSame('accepted', $resolved['status']);
        $this->assertSame('1992-09-09', $patient->fresh()->dob->format('Y-m-d'));
        $this->assertSame(3, (int) $patient->fresh()->version);
    }

    public function test_resolving_as_theirs_needs_no_second_operation_at_all(): void
    {
        $uuid = (string) Str::uuid();
        $this->pushAsClerk([$this->op('patients', 'create', [
            'uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato', 'dob' => '1990-01-01',
        ])])->assertOk();

        $patient = Patient::where('uuid', $uuid)->firstOrFail();
        $patient->update(['dob' => '1991-05-05']);
        $patient->forceFill(['version' => 2])->saveQuietly();

        $this->pushAsClerk([$this->op('patients', 'update', [
            'uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato', 'dob' => '1992-09-09',
        ], ['base_version' => 1, 'base_fields' => ['dob' => '1990-01-01']])])->assertOk();

        // "The server's is right" is decided entirely on the device: the
        // conflicted operation is cancelled locally and nothing is sent. The
        // server's value was never at risk.
        $this->assertSame('1991-05-05', $patient->fresh()->dob->format('Y-m-d'));
        $this->assertSame(2, (int) $patient->fresh()->version);
        $this->assertSame(1, SyncConflict::count());
    }

    // ── Status and registration ──────────────────────────────────────────

    public function test_status_says_what_this_server_accepts_and_what_time_it_thinks_it_is(): void
    {
        $data = $this->withHeader('X-Device-Id', $this->device->device_uuid)
            ->getJson(route('api.sync.status'))->assertOk()->json('data');

        $this->assertSame(1, $data['protocol_version']);
        $this->assertNotNull($data['server_time']);
        $this->assertContains('vitals', $data['accepts']);
        $this->assertSame($this->device->device_uuid, $data['device']['device_uuid']);
    }

    public function test_registering_a_device_is_recorded_as_a_deliberate_act(): void
    {
        $uuid = (string) Str::uuid();

        $this->postJson(route('api.sync.register'), [
            'device_uuid' => $uuid, 'label' => 'Maternity desk laptop', 'platform' => 'Chrome/macOS',
        ])->assertOk();

        $device = Device::where('device_uuid', $uuid)->firstOrFail();

        $this->assertSame('Maternity desk laptop', $device->label);
        $this->assertSame($this->hospital->id, $device->hospital_id);
        $this->assertDatabaseHas('activity_log', ['description' => 'offline device registered']);
    }

    public function test_a_revoked_device_cannot_re_register_itself_out_of_trouble(): void
    {
        $this->device->update(['revoked_at' => now()]);

        $this->postJson(route('api.sync.register'), [
            'device_uuid' => $this->device->device_uuid, 'label' => 'Trying again',
        ])->assertForbidden();

        $this->assertNotNull($this->device->fresh()->revoked_at);
    }

    public function test_sync_needs_authentication_like_everything_else(): void
    {
        app()->forgetInstance('request');
        $this->app['auth']->forgetGuards();

        $this->postJson(route('api.sync.push'), ['operations' => []])->assertUnauthorized();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function visit(): Visit
    {
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);

        return Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $patient->id]);
    }

    private function admission(): Admission
    {
        $ward = Ward::factory()->create(['hospital_id' => $this->hospital->id]);
        $bed = Bed::factory()->create(['hospital_id' => $this->hospital->id, 'ward_id' => $ward->id]);
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);

        return app(AdmissionService::class)->admit($patient, $bed, [], $this->nurse->id);
    }
}
