<?php

namespace Tests\Feature\Offline;

use App\Enums\VisitStage;
use App\Models\Device;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\SyncConflict;
use App\Models\User;
use App\Models\Visit;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A doctor's clinical narrative, written away from a desk.
 *
 * The second three-way merge in the system, and the stricter of the two. For
 * demographics, merging a phone number one side changed with an address the
 * other side changed is obviously right — they are independent facts about a
 * person. A diagnosis is not an independent fact: two clinicians writing
 * different ones for the same visit is a disagreement about the patient, and
 * no rule should decide it.
 *
 * So the merge still does the useful half — a field only the device moved is
 * applied — and a field both sides moved is always raised, never resolved.
 */
class VisitClinicalSyncTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $doctor;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->doctor = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor']);
        $this->doctor->syncSpatieRole();

        $this->device = Device::create([
            'hospital_id' => $this->hospital->id,
            'user_id' => $this->doctor->id,
            'device_uuid' => (string) Str::uuid(),
            'label' => 'Consulting room tablet',
            'registered_at' => now(),
        ]);
    }

    private function visit(array $attributes = []): Visit
    {
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);

        return Visit::factory()->create(array_merge([
            'hospital_id' => $this->hospital->id,
            'patient_id' => $patient->id,
            'stage' => VisitStage::Ongoing,
            'complaints' => 'Headache for three days.',
            'diagnosis' => null,
            'doctor_remarks' => null,
        ], $attributes));
    }

    private function op(array $payload, array $extra = []): array
    {
        return array_merge([
            'operation_id' => strtoupper((string) Str::ulid()),
            'entity' => 'visits',
            'entity_uuid' => $payload['uuid'],
            'operation' => 'update',
            'payload' => $payload,
        ], $extra);
    }

    private function push(array $ops, ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->doctor);

        return $this->withHeader('X-Device-Id', $this->device->device_uuid)
            ->postJson(route('api.sync.push'), ['operations' => $ops]);
    }

    // ── The happy path ───────────────────────────────────────────────────

    public function test_a_note_written_on_a_ward_round_reaches_the_visit(): void
    {
        $visit = $this->visit();

        $result = $this->push([$this->op([
            'uuid' => $visit->uuid,
            'complaints' => 'Headache for three days.',
            'diagnosis' => 'Tension headache.',
            'doctor_remarks' => 'Advised rest and fluids.',
        ], ['base_version' => (int) $visit->version])])->assertOk()->json('data.results.0');

        $this->assertSame('accepted', $result['status']);

        $visit->refresh();

        $this->assertSame('Tension headache.', $visit->diagnosis);
        $this->assertSame('Advised rest and fluids.', $visit->doctor_remarks);
        $this->assertSame(2, (int) $visit->version);
    }

    public function test_a_replay_changes_nothing_and_returns_the_original_answer(): void
    {
        $visit = $this->visit();
        $op = $this->op([
            'uuid' => $visit->uuid, 'diagnosis' => 'Tension headache.',
        ], ['base_version' => (int) $visit->version]);

        $this->push([$op])->assertOk();
        $second = $this->push([$op])->assertOk()->json('data.results.0');

        $this->assertSame('already_processed', $second['status']);
        // Not 3: the replay must not advance the version, or the next edit
        // from this device is contested against a version nothing wrote.
        $this->assertSame(2, (int) $visit->fresh()->version);
    }

    // ── What a device may not do ─────────────────────────────────────────

    public function test_a_visit_cannot_be_opened_from_a_device(): void
    {
        // `visit_no` comes from `Support\Sequence` under a row lock, and
        // opening a visit bills a consultation. Neither belongs on a tablet.
        $visit = $this->visit();

        $result = $this->push([$this->op(
            ['uuid' => (string) Str::uuid(), 'diagnosis' => 'Malaria.'],
            ['operation' => 'create'],
        )])->assertOk()->json('data.results.0');

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('unsupported_operation', $result['reason_code']);
        $this->assertSame(1, Visit::count(), 'A visit was created from a device.');
        $this->assertSame($visit->id, Visit::first()->id);
    }

    public function test_the_stage_and_the_assigned_doctor_are_not_a_devices_business(): void
    {
        // A device sending them is not refused — a newer client may know
        // fields this server does not — but they are DROPPED, not applied.
        // Reassigning a visit has a queue behind it and moving its stage
        // checks gates a device cannot see.
        $visit = $this->visit();
        $other = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor']);

        $this->push([$this->op([
            'uuid' => $visit->uuid,
            'diagnosis' => 'Tension headache.',
            'stage' => 'completed',
            'doctor_user_id' => $other->id,
            'visit_no' => 'VS-FORGED-0001',
        ], ['base_version' => (int) $visit->version])])->assertOk();

        $visit->refresh();

        $this->assertSame('Tension headache.', $visit->diagnosis);
        $this->assertSame(VisitStage::Ongoing, $visit->stage);
        $this->assertNotSame($other->id, $visit->doctor_user_id);
        $this->assertNotSame('VS-FORGED-0001', $visit->visit_no);
    }

    public function test_a_visit_that_has_moved_on_to_billing_is_refused(): void
    {
        // Amending a narrative underneath an invoice somebody has already been
        // shown is done in the panel, where the bill is visible.
        $visit = $this->visit(['stage' => VisitStage::Billing]);

        $result = $this->push([$this->op([
            'uuid' => $visit->uuid, 'diagnosis' => 'Tension headache.',
        ], ['base_version' => (int) $visit->version])])->assertOk()->json('data.results.0');

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('parent_closed', $result['reason_code']);
        $this->assertNull($visit->fresh()->diagnosis);
    }

    public function test_a_role_that_cannot_diagnose_is_refused(): void
    {
        $visit = $this->visit();
        $clerk = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'receptionist']);
        $clerk->syncSpatieRole();

        $result = $this->push([$this->op([
            'uuid' => $visit->uuid, 'diagnosis' => 'Tension headache.',
        ], ['base_version' => (int) $visit->version])], $clerk)->assertOk()->json('data.results.0');

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('forbidden', $result['reason_code']);
        $this->assertNull($visit->fresh()->diagnosis);
    }

    public function test_a_visit_that_is_not_here_is_refused_rather_than_created(): void
    {
        $result = $this->push([$this->op([
            'uuid' => (string) Str::uuid(), 'diagnosis' => 'Malaria.',
        ], ['base_version' => 1])])->assertOk()->json('data.results.0');

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('not_found', $result['reason_code']);
    }

    public function test_an_empty_note_is_refused_rather_than_written(): void
    {
        $visit = $this->visit();

        $result = $this->push([$this->op([
            'uuid' => $visit->uuid,
        ], ['base_version' => (int) $visit->version])])->assertOk()->json('data.results.0');

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('validation_failed', $result['reason_code']);
    }

    // ── The merge ────────────────────────────────────────────────────────

    public function test_a_field_only_the_device_moved_is_applied(): void
    {
        $visit = $this->visit();

        // Somebody corrected the complaints in the panel while the device was
        // away. The device is writing a diagnosis, which nobody else touched.
        $visit->forceFill(['complaints' => 'Headache and photophobia.', 'version' => 2])->saveQuietly();

        $result = $this->push([$this->op([
            'uuid' => $visit->uuid,
            'diagnosis' => 'Migraine.',
        ], [
            'base_version' => 1,
            'base_fields' => ['diagnosis' => null],
        ])])->assertOk()->json('data.results.0');

        $this->assertSame('accepted', $result['status']);

        $visit->refresh();

        $this->assertSame('Migraine.', $visit->diagnosis);
        // And the other side's correction survived. Losing it would be exactly
        // the overwrite the three-way merge exists to prevent.
        $this->assertSame('Headache and photophobia.', $visit->complaints);
    }

    /**
     * A device sends the whole narrative, including fields it did not touch.
     * One it left at its confirmed value is not a disagreement with a remark
     * somebody added on the web meanwhile — found by the app's end-to-end test.
     */
    public function test_a_field_the_device_left_alone_is_not_raised_when_the_web_moved_it(): void
    {
        $visit = $this->visit();
        $visit->forceFill(['doctor_remarks' => 'Seen on the ward round.', 'version' => 2])->saveQuietly();

        $result = $this->push([$this->op([
            'uuid' => $visit->uuid,
            'complaints' => $visit->complaints,
            'diagnosis' => 'Migraine.',
            'doctor_remarks' => null,
        ], [
            'base_version' => 1,
            'base_fields' => ['complaints' => $visit->complaints, 'diagnosis' => null, 'doctor_remarks' => null],
        ])])->assertOk()->json('data.results.0');

        $this->assertSame('accepted', $result['status']);
        $visit->refresh();
        $this->assertSame('Migraine.', $visit->diagnosis);
        $this->assertSame('Seen on the ward round.', $visit->doctor_remarks);
    }

    public function test_a_field_both_sides_moved_is_always_raised_and_never_merged(): void
    {
        $visit = $this->visit();

        $visit->forceFill(['diagnosis' => 'Migraine with aura.', 'version' => 2])->saveQuietly();

        $result = $this->push([$this->op([
            'uuid' => $visit->uuid,
            'diagnosis' => 'Tension headache.',
        ], [
            'base_version' => 1,
            'base_fields' => ['diagnosis' => null],
        ])])->assertOk()->json('data.results.0');

        $this->assertSame('conflict', $result['status']);
        // `manual`, never `field_merge`. There is no safe automatic answer to
        // two clinicians writing different diagnoses for one visit.
        $this->assertSame('manual', $result['conflict']['strategy']);
        $this->assertSame(['diagnosis'], $result['conflict']['contested']);
        $this->assertSame('Migraine with aura.', $result['conflict']['server_state']['diagnosis']);

        // The server's value stands until a person says otherwise.
        $this->assertSame('Migraine with aura.', $visit->fresh()->diagnosis);
        $this->assertSame(1, SyncConflict::count());
    }

    public function test_the_uncontested_half_is_still_written_when_the_other_half_is_raised(): void
    {
        // A doctor who added remarks to a visit whose diagnosis somebody else
        // corrected must not lose the remarks over it.
        $visit = $this->visit();

        $visit->forceFill(['diagnosis' => 'Migraine with aura.', 'version' => 2])->saveQuietly();

        $result = $this->push([$this->op([
            'uuid' => $visit->uuid,
            'diagnosis' => 'Tension headache.',
            'doctor_remarks' => 'Advised rest and fluids.',
        ], [
            'base_version' => 1,
            'base_fields' => ['diagnosis' => null, 'doctor_remarks' => null],
        ])])->assertOk()->json('data.results.0');

        $this->assertSame('conflict', $result['status']);
        $this->assertSame(['diagnosis'], $result['conflict']['contested']);
        $this->assertSame(['doctor_remarks'], $result['conflict']['merged']);

        $visit->refresh();

        $this->assertSame('Advised rest and fluids.', $visit->doctor_remarks);
        $this->assertSame('Migraine with aura.', $visit->diagnosis);
    }

    public function test_a_field_with_no_base_is_treated_as_contested_rather_than_overwritten(): void
    {
        // An older client, or one that has never had the field confirmed.
        // Conservative on purpose: it must not overwrite a clinical sentence
        // it has never seen.
        $visit = $this->visit();

        $visit->forceFill(['diagnosis' => 'Migraine with aura.', 'version' => 2])->saveQuietly();

        $result = $this->push([$this->op([
            'uuid' => $visit->uuid, 'diagnosis' => 'Tension headache.',
        ], ['base_version' => 1])])->assertOk()->json('data.results.0');

        $this->assertSame('conflict', $result['status']);
        $this->assertSame('Migraine with aura.', $visit->fresh()->diagnosis);
    }

    public function test_a_device_echoing_what_is_already_there_is_accepted_as_a_no_op(): void
    {
        // The device is simply behind. Accepting it costs nothing and stops
        // the operation sitting in the outbox for ever with nothing to fix.
        $visit = $this->visit();

        $visit->forceFill(['diagnosis' => 'Migraine.', 'version' => 4])->saveQuietly();

        $result = $this->push([$this->op([
            'uuid' => $visit->uuid, 'diagnosis' => 'Migraine.',
        ], ['base_version' => 2, 'base_fields' => ['diagnosis' => null]])])->assertOk()->json('data.results.0');

        $this->assertSame('accepted', $result['status']);
        $this->assertSame(4, (int) $visit->fresh()->version, 'A no-op moved the version.');
        $this->assertSame(0, SyncConflict::count());
    }

    public function test_base_version_zero_means_this_device_is_the_only_writer(): void
    {
        // A record captured and edited before its first sync. There is nothing
        // to merge against and no conflict to raise.
        $visit = $this->visit();

        $result = $this->push([$this->op([
            'uuid' => $visit->uuid, 'diagnosis' => 'Tension headache.',
        ], ['base_version' => 0])])->assertOk()->json('data.results.0');

        $this->assertSame('accepted', $result['status']);
        $this->assertSame('Tension headache.', $visit->fresh()->diagnosis);
    }

    // ── What the device has to be given for any of this to work ──────────

    public function test_a_doctors_device_receives_the_narrative_it_will_be_editing(): void
    {
        // Not a nicety. Without these fields the device has no CONFIRMED base
        // for them, so `base_fields` arrives empty and the handler — correctly
        // — treats every field as contested. Every note written offline would
        // come back as a conflict.
        $visit = $this->visit(['diagnosis' => 'Migraine.', 'doctor_remarks' => 'Review in a week.']);

        Sanctum::actingAs($this->doctor);

        $record = collect(
            $this->withHeader('X-Device-Id', $this->device->device_uuid)
                ->getJson(route('api.sync.pull'))->assertOk()->json('data.changes')
        )->firstWhere('entity', 'visits')['record'];

        $this->assertSame($visit->uuid, $record['uuid']);
        $this->assertSame('Headache for three days.', $record['complaints']);
        $this->assertSame('Migraine.', $record['diagnosis']);
        $this->assertSame('Review in a week.', $record['doctor_remarks']);
    }

    public function test_a_receptionists_device_receives_the_visit_without_the_diagnosis(): void
    {
        // They need to know a visit is open and whose it is. They do not need
        // the diagnosis on their machine, and the smallest defensible copy is
        // the one that is not there.
        $this->visit(['diagnosis' => 'Migraine.']);

        $clerk = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'receptionist']);
        $clerk->syncSpatieRole();

        Sanctum::actingAs($clerk);

        $device = Device::create([
            'hospital_id' => $this->hospital->id,
            'user_id' => $clerk->id,
            'device_uuid' => (string) Str::uuid(),
            'label' => 'Front desk',
            'registered_at' => now(),
        ]);

        $record = collect(
            $this->withHeader('X-Device-Id', $device->device_uuid)
                ->getJson(route('api.sync.pull'))->assertOk()->json('data.changes')
        )->firstWhere('entity', 'visits')['record'];

        $this->assertArrayHasKey('visit_no', $record);
        $this->assertArrayNotHasKey('diagnosis', $record);
        $this->assertArrayNotHasKey('complaints', $record);
        $this->assertArrayNotHasKey('doctor_remarks', $record);
    }

    // ── Tenancy ──────────────────────────────────────────────────────────

    public function test_a_visit_in_another_hospital_is_invisible_rather_than_refused_by_name(): void
    {
        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $theirPatient = Patient::factory()->create(['hospital_id' => $other->id]);
        $theirVisit = Visit::factory()->create([
            'hospital_id' => $other->id,
            'patient_id' => $theirPatient->id,
            'stage' => VisitStage::Ongoing,
            'diagnosis' => 'Theirs.',
        ]);
        app(CurrentHospital::class)->set($this->hospital->id);

        $result = $this->push([$this->op([
            'uuid' => $theirVisit->uuid, 'diagnosis' => 'Mine.',
        ], ['base_version' => 1])])->assertOk()->json('data.results.0');

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('not_found', $result['reason_code']);
        $this->assertSame('Theirs.', $theirVisit->fresh()->diagnosis);
    }
}
