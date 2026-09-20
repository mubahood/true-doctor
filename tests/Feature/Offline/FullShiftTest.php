<?php

namespace Tests\Feature\Offline;

use App\Models\Admission;
use App\Models\Bed;
use App\Models\Device;
use App\Models\Hospital;
use App\Models\MedicationAdministration;
use App\Models\NursingNote;
use App\Models\Patient;
use App\Models\SyncOperation;
use App\Models\User;
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
 * A whole shift, end to end, with things going wrong in the middle.
 *
 * Every other test in this directory proves one thing. This one proves they
 * work TOGETHER, which is a different claim: a component can be perfect on its
 * own and wrong once something else is holding it.
 *
 * The shape is the one the plan describes (§ final full-system test):
 *
 *   sign in → pull the ward → go offline → work a shift → lose a response
 *   → come back → sync → check both databases agree and nothing is doubled
 */
class FullShiftTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $nurse;

    private User $clerk;

    private Device $tablet;

    private Device $desk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->nurse = $this->staff('nurse');
        $this->clerk = $this->staff('receptionist');

        $this->tablet = $this->device($this->nurse, 'Maternity tablet');
        $this->desk = $this->device($this->clerk, 'Reception desk');
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => $role]);
        $user->syncSpatieRole();

        return $user;
    }

    private function device(User $user, string $label): Device
    {
        return Device::create([
            'hospital_id' => $this->hospital->id,
            'user_id' => $user->id,
            'device_uuid' => (string) Str::uuid(),
            'label' => $label,
            'registered_at' => now(),
        ]);
    }

    /** One device's outbox, as the client would hold it. */
    private function queue(): array
    {
        return [];
    }

    private function op(string $entity, string $operation, array $payload, array $extra = []): array
    {
        return array_merge([
            'operation_id' => strtoupper((string) Str::ulid()),
            'entity' => $entity,
            'entity_uuid' => $payload['uuid'],
            'operation' => $operation,
            'payload' => $payload,
            'client_created_at' => now()->toIso8601String(),
        ], $extra);
    }

    private function push(array $operations, User $as, Device $from)
    {
        Sanctum::actingAs($as);

        return $this->withHeader('X-Device-Id', $from->device_uuid)
            ->postJson(route('api.sync.push'), ['operations' => $operations]);
    }

    private function pull(User $as, Device $from, ?string $cursor = null)
    {
        Sanctum::actingAs($as);

        return $this->withHeader('X-Device-Id', $from->device_uuid)
            ->getJson(route('api.sync.pull').($cursor ? '?cursor='.urlencode($cursor) : ''));
    }

    private function ward(): Admission
    {
        $ward = Ward::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Maternity']);
        $bed = Bed::factory()->create(['hospital_id' => $this->hospital->id, 'ward_id' => $ward->id, 'name' => 'M-04']);
        $patient = Patient::factory()->create([
            'hospital_id' => $this->hospital->id, 'first_name' => 'Grace', 'last_name' => 'Auma',
        ]);

        return app(AdmissionService::class)->admit($patient, $bed, ['reason' => 'Obstructed labour'], $this->nurse->id);
    }

    // ── The shift ────────────────────────────────────────────────────────

    public function test_a_full_shift_offline_survives_a_lost_response_and_ends_consistent(): void
    {
        // ── Before the connection goes ────────────────────────────────────
        $admission = $this->ward();

        $firstPull = $this->pull($this->nurse, $this->tablet)->assertOk()->json('data');
        $cursor = $firstPull['next_cursor'];

        $entities = array_column($firstPull['changes'], 'entity');
        $this->assertContains('patients', $entities);
        $this->assertContains('admissions', $entities);

        // ── The connection drops. A shift's work accumulates locally ──────
        $newPatientUuid = (string) Str::uuid();
        $vitalsUuids = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $noteUuid = (string) Str::uuid();
        $doseUuid = (string) Str::uuid();

        $deskWork = [
            $this->op('patients', 'create', [
                'uuid' => $newPatientUuid, 'first_name' => 'Amina', 'last_name' => 'Nakato',
                'phone_1' => '0700111222', 'allergies' => ['Penicillin'],
            ]),
        ];

        $wardWork = [];
        foreach ($vitalsUuids as $i => $uuid) {
            $wardWork[] = $this->op('vitals', 'create', [
                'uuid' => $uuid, 'admission_uuid' => $admission->uuid,
                'temperature' => 36.8 + ($i / 10), 'pulse' => 74 + $i, 'blood_pressure' => '118/76',
            ]);
        }
        $wardWork[] = $this->op('nursing_notes', 'create', [
            'uuid' => $noteUuid, 'admission_uuid' => $admission->uuid, 'note' => 'Comfortable overnight.',
        ]);
        $wardWork[] = $this->op('med_administrations', 'create', [
            'uuid' => $doseUuid, 'admission_uuid' => $admission->uuid,
            'drug_name' => 'Paracetamol', 'dose' => '1g', 'route' => 'oral', 'status' => 'given',
        ]);

        // ── The connection comes back. Reception syncs first ──────────────
        $deskResults = $this->push($deskWork, $this->clerk, $this->desk)->assertOk()->json('data.results');

        $this->assertSame('accepted', $deskResults[0]['status']);
        $assignedNumber = $deskResults[0]['assigned']['patient_no'];
        $this->assertNotEmpty($assignedNumber);

        // ── The ward tablet pushes — and the reply is lost ────────────────
        $firstAttempt = $this->push($wardWork, $this->nurse, $this->tablet)->assertOk()->json('data.results');
        $this->assertSame(5, collect($firstAttempt)->where('status', 'accepted')->count());

        // From the tablet's side nothing came back, so it retries everything.
        $retry = $this->push($wardWork, $this->nurse, $this->tablet)->assertOk()->json('data.results');

        $this->assertSame(5, collect($retry)->where('status', 'already_processed')->count());

        // ── Now check both sides agree ────────────────────────────────────

        // Nothing doubled.
        $this->assertSame(3, VitalRound::count(), 'Three rounds were recorded and three exist.');
        $this->assertSame(1, NursingNote::count());
        $this->assertSame(1, MedicationAdministration::count());
        $this->assertSame(2, Patient::count(), 'Grace plus Amina — the retry made no third.');

        // Nothing lost.
        foreach ($vitalsUuids as $uuid) {
            $this->assertDatabaseHas('vital_rounds', ['uuid' => $uuid]);
        }
        $this->assertDatabaseHas('nursing_notes', ['uuid' => $noteUuid]);
        $this->assertDatabaseHas('medication_administrations', ['uuid' => $doseUuid]);

        // Identity kept (I-8), number issued by the server (I-11).
        $amina = Patient::where('uuid', $newPatientUuid)->firstOrFail();
        $this->assertSame($newPatientUuid, $amina->uuid);
        $this->assertSame($assignedNumber, $amina->patient_no);
        $this->assertSame(['Penicillin'], $amina->allergies);

        // Relationships intact.
        foreach (VitalRound::all() as $round) {
            $this->assertSame($admission->id, $round->admission_id);
            $this->assertSame($this->nurse->id, $round->recorded_by);
        }

        // Provenance: every offline record knows which device and which
        // operation produced it, and what the device thought the time was.
        foreach (VitalRound::all() as $round) {
            $this->assertSame($this->tablet->id, $round->origin_device_id);
            $this->assertNotNull($round->origin_operation_id);
            $this->assertNotNull($round->client_created_at);
        }

        // The ledger recorded each operation exactly once (I-1).
        $this->assertSame(6, SyncOperation::count());
        $this->assertSame(6, SyncOperation::distinct('operation_id')->count('operation_id'));

        // ── The tablet pulls again and sees the desk's work ───────────────
        $second = $this->pull($this->nurse, $this->tablet, $cursor)->assertOk()->json('data');
        $pulledUuids = array_column(array_column($second['changes'], 'record'), 'uuid');

        $this->assertContains($newPatientUuid, $pulledUuids, "The ward should learn about reception's new patient.");

        // And the number it now carries is the server's, not a provisional one.
        $pulledAmina = collect($second['changes'])->firstWhere('record.uuid', $newPatientUuid);
        $this->assertSame($assignedNumber, $pulledAmina['record']['patient_no']);
    }

    // ── The same shift, with more going wrong ────────────────────────────

    public function test_a_shift_where_half_the_batch_is_refused_still_keeps_the_good_half(): void
    {
        $admission = $this->ward();

        $good = (string) Str::uuid();
        $bad = (string) Str::uuid();

        $results = $this->push([
            $this->op('vitals', 'create', ['uuid' => $good, 'admission_uuid' => $admission->uuid, 'pulse' => 78]),
            // A typo nobody caught at the bedside.
            $this->op('vitals', 'create', ['uuid' => $bad, 'admission_uuid' => $admission->uuid, 'pulse' => 9000]),
        ], $this->nurse, $this->tablet)->assertOk()->json('data.results');

        $this->assertSame('accepted', $results[0]['status']);
        $this->assertSame('rejected', $results[1]['status']);

        // The good one landed. One bad reading must not cost a shift its work.
        $this->assertSame(1, VitalRound::count());
        $this->assertDatabaseHas('vital_rounds', ['uuid' => $good]);
        $this->assertDatabaseMissing('vital_rounds', ['uuid' => $bad]);

        // Corrected and re-sent under a NEW operation id, because it is a new
        // attempt at a record the server never accepted.
        $corrected = $this->push([
            $this->op('vitals', 'create', ['uuid' => $bad, 'admission_uuid' => $admission->uuid, 'pulse' => 90]),
        ], $this->nurse, $this->tablet)->assertOk()->json('data.results.0');

        $this->assertSame('accepted', $corrected['status']);
        $this->assertSame(2, VitalRound::count());
    }

    public function test_a_shift_recorded_against_a_patient_who_went_home_is_refused_not_lost(): void
    {
        $admission = $this->ward();

        // The tablet has been offline. The patient was discharged at the desk.
        app(AdmissionService::class)->discharge(
            $admission, \App\Enums\AdmissionStatus::Discharged, 'Well.', $this->clerk->id,
        );

        $result = $this->push([
            $this->op('nursing_notes', 'create', [
                'uuid' => (string) Str::uuid(), 'admission_uuid' => $admission->uuid, 'note' => 'Settled.',
            ]),
        ], $this->nurse, $this->tablet)->assertOk()->json('data.results.0');

        // Refused with a reason a person can act on, and the note is still on
        // the device for somebody to re-enter where it belongs.
        $this->assertSame('rejected', $result['status']);
        $this->assertSame('parent_closed', $result['reason_code']);
        $this->assertStringContainsString('closed', $result['message']);
        $this->assertSame(0, NursingNote::count());
    }

    public function test_two_devices_working_the_same_ward_do_not_tread_on_each_other(): void
    {
        $admission = $this->ward();
        $secondTablet = $this->device($this->nurse, 'Night tablet');

        // Two nurses, two rounds, in the same minute.
        $this->push([$this->op('vitals', 'create', [
            'uuid' => (string) Str::uuid(), 'admission_uuid' => $admission->uuid, 'pulse' => 70,
        ])], $this->nurse, $this->tablet)->assertOk();

        $this->push([$this->op('vitals', 'create', [
            'uuid' => (string) Str::uuid(), 'admission_uuid' => $admission->uuid, 'pulse' => 96,
        ])], $this->nurse, $secondTablet)->assertOk();

        // Append-only: two rounds recorded, two rounds exist. Both are true,
        // and neither device had to know about the other.
        $this->assertSame(2, VitalRound::count());
        $this->assertSame([70, 96], VitalRound::orderBy('id')->pluck('pulse')->all());

        // Each round knows which tablet it came from.
        $devices = VitalRound::orderBy('id')->pluck('origin_device_id')->all();
        $this->assertSame([$this->tablet->id, $secondTablet->id], $devices);
    }

    public function test_a_device_blocked_mid_shift_is_stopped_and_told_why(): void
    {
        $admission = $this->ward();

        $this->push([$this->op('vitals', 'create', [
            'uuid' => (string) Str::uuid(), 'admission_uuid' => $admission->uuid, 'pulse' => 70,
        ])], $this->nurse, $this->tablet)->assertOk();

        $this->tablet->update([
            'revoked_at' => now(),
            'revoked_reason' => 'This tablet was reported lost.',
        ]);

        $response = $this->push([$this->op('vitals', 'create', [
            'uuid' => (string) Str::uuid(), 'admission_uuid' => $admission->uuid, 'pulse' => 74,
        ])], $this->nurse, $this->tablet)->assertForbidden();

        $this->assertSame('device_revoked', $response->json('code'));
        $this->assertSame('This tablet was reported lost.', $response->json('message'));

        // The work it already sent stays. Revocation stops the future, not the
        // past — those readings are part of a patient's chart.
        $this->assertSame(1, VitalRound::count());
    }

    public function test_the_shift_leaves_a_trail_that_answers_who_what_when_and_from_where(): void
    {
        $admission = $this->ward();
        $uuid = (string) Str::uuid();
        $clientTime = now()->subMinutes(35);

        $this->push([$this->op('vitals', 'create', [
            'uuid' => $uuid, 'admission_uuid' => $admission->uuid, 'pulse' => 78,
        ], ['client_created_at' => $clientTime->toIso8601String()])], $this->nurse, $this->tablet)->assertOk();

        $round = VitalRound::where('uuid', $uuid)->firstOrFail();
        $operation = SyncOperation::where('entity_uuid', $uuid)->firstOrFail();

        // Who
        $this->assertSame($this->nurse->id, $round->recorded_by);
        $this->assertSame($this->nurse->id, $operation->user_id);
        // From where
        $this->assertSame($this->tablet->id, $round->origin_device_id);
        // What
        $this->assertSame('vitals', $operation->entity);
        $this->assertSame('accepted', $operation->status);
        // When — both clocks, and the server's is the authority
        $this->assertEqualsWithDelta($clientTime->timestamp, $round->client_created_at->timestamp, 2);
        $this->assertTrue($round->created_at->gt($round->client_created_at));

        // And what was IN it is not in the trail.
        $this->assertNotNull($operation->payload_hash);
        $this->assertStringNotContainsString('78', (string) json_encode($operation->result));
    }
}
