<?php

namespace Tests\Feature\Offline;

use App\Models\Bed;
use App\Models\Device;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Services\Sync\PullCursor;
use App\Services\Sync\PullService;
use App\Support\CurrentHospital;
use App\Support\SyncRevision;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pull — server changes reaching a device.
 *
 * The property that matters most here is invariant I-4: the cursor advances
 * only after the device has committed the page. The server's side of that
 * bargain is that a cursor it did not issue is refused, and that a change with
 * a revision it has already sent is never re-sent out of order.
 */
class SyncPullTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $nurse;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->nurse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $this->nurse->syncSpatieRole();

        $this->device = Device::create([
            'hospital_id' => $this->hospital->id,
            'user_id' => $this->nurse->id,
            'device_uuid' => (string) Str::uuid(),
            'label' => 'Ward tablet',
            'registered_at' => now(),
        ]);

        Sanctum::actingAs($this->nurse);
    }

    private function pull(?string $cursor = null, ?Device $device = null)
    {
        return $this->withHeader('X-Device-Id', ($device ?? $this->device)->device_uuid)
            ->getJson(route('api.sync.pull').($cursor ? '?cursor='.urlencode($cursor) : ''));
    }

    private function patients(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            Patient::factory()->create(['hospital_id' => $this->hospital->id, 'first_name' => "Patient{$i}"]);
        }
    }

    // ── Revisions ────────────────────────────────────────────────────────

    public function test_every_write_gets_a_revision_including_ones_made_online(): void
    {
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);

        $this->assertNotNull($patient->fresh()->sync_revision);

        $first = (int) $patient->fresh()->sync_revision;
        $patient->update(['phone_1' => '0700000000']);

        // An edit moves it forward, or a device already past the first revision
        // would never hear about the change.
        $this->assertGreaterThan($first, (int) $patient->fresh()->sync_revision);
    }

    public function test_a_save_that_changes_nothing_does_not_burn_a_revision(): void
    {
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $before = (int) $patient->fresh()->sync_revision;

        $patient->fresh()->save();

        $this->assertSame($before, (int) $patient->fresh()->sync_revision);
    }

    public function test_revisions_are_per_hospital_and_never_shared(): void
    {
        $theirs = Hospital::factory()->create();

        app(CurrentHospital::class)->set($this->hospital->id);
        Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $ours = SyncRevision::current($this->hospital->id);

        app(CurrentHospital::class)->set($theirs->id);
        Patient::factory()->create(['hospital_id' => $theirs->id]);

        // Two hospitals counting independently — a shared counter would leak
        // the other tenant's write rate and make cursors meaningless.
        $this->assertSame($ours, SyncRevision::current($this->hospital->id));
        $this->assertSame(1, SyncRevision::current($theirs->id));
    }

    // ── Paging ───────────────────────────────────────────────────────────

    public function test_a_first_pull_delivers_the_working_set_oldest_first(): void
    {
        $this->patients(5);

        $data = $this->pull()->assertOk()->json('data');

        $this->assertCount(5, $data['changes']);
        $this->assertFalse($data['has_more']);

        $revisions = array_column($data['changes'], 'revision');
        $sorted = $revisions;
        sort($sorted);

        $this->assertSame($sorted, $revisions);
        $this->assertSame('patients', $data['changes'][0]['entity']);
    }

    public function test_a_second_pull_with_the_cursor_returns_only_what_is_new(): void
    {
        $this->patients(3);

        $first = $this->pull()->json('data');
        $this->assertCount(3, $first['changes']);

        // Nothing changed in between.
        $this->assertCount(0, $this->pull($first['next_cursor'])->json('data.changes'));

        Patient::factory()->create(['hospital_id' => $this->hospital->id, 'first_name' => 'Latecomer']);

        $third = $this->pull($first['next_cursor'])->json('data');

        $this->assertCount(1, $third['changes']);
        $this->assertSame('Latecomer', $third['changes'][0]['record']['first_name']);
    }

    public function test_a_long_stream_pages_and_says_there_is_more(): void
    {
        $this->patients(PullService::PAGE + 25);

        $page = $this->pull()->json('data');

        $this->assertCount(PullService::PAGE, $page['changes']);
        $this->assertTrue($page['has_more']);

        $rest = $this->pull($page['next_cursor'])->json('data');

        $this->assertCount(25, $rest['changes']);
        $this->assertFalse($rest['has_more']);

        // Every record delivered exactly once across the two pages.
        $ids = array_merge(
            array_column(array_column($page['changes'], 'record'), 'uuid'),
            array_column(array_column($rest['changes'], 'record'), 'uuid'),
        );
        $this->assertCount(PullService::PAGE + 25, array_unique($ids));
    }

    /**
     * Streams are interleaved by revision, not concatenated.
     *
     * Concatenating would put every patient before every visit, so a cursor
     * advanced past a full page of patients would skip the visits written in
     * the same window — silently, and for ever.
     */
    public function test_changes_from_different_tables_come_back_in_one_ordered_stream(): void
    {
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $visit = Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $patient->id]);
        $patient2 = Patient::factory()->create(['hospital_id' => $this->hospital->id]);

        $changes = $this->pull()->json('data.changes');
        $entities = array_column($changes, 'entity');

        $this->assertContains('patients', $entities);
        $this->assertContains('visits', $entities);

        $revisions = array_column($changes, 'revision');
        $sorted = $revisions;
        sort($sorted);
        $this->assertSame($sorted, $revisions);
    }

    // ── Cursors ──────────────────────────────────────────────────────────

    public function test_a_cursor_this_server_did_not_issue_starts_from_the_beginning(): void
    {
        $this->patients(3);

        // Not a refusal with an explanation: a forged cursor is a claim to data
        // the caller has no business asking for, and telling it what it got
        // wrong would help it guess better.
        $changes = $this->pull('not-a-real-cursor')->assertOk()->json('data.changes');

        $this->assertCount(3, $changes);
    }

    public function test_another_devices_cursor_is_not_usable(): void
    {
        $this->patients(3);
        $mine = $this->pull()->json('data.next_cursor');

        $other = Device::create([
            'hospital_id' => $this->hospital->id,
            'user_id' => $this->nurse->id,
            'device_uuid' => (string) Str::uuid(),
            'label' => 'Another tablet',
            'registered_at' => now(),
        ]);

        // The second device gets the whole working set, not the first device's
        // position — cursors are per device by construction.
        $this->assertCount(3, $this->pull($mine, $other)->json('data.changes'));
    }

    public function test_a_cursor_never_goes_backwards(): void
    {
        $cursor = new PullCursor($this->hospital->id, 50, $this->device->device_uuid);

        $this->assertSame(50, $cursor->advancedTo(20)->revision);
        $this->assertSame(80, $cursor->advancedTo(80)->revision);
    }

    public function test_a_cursor_decodes_only_for_its_own_hospital(): void
    {
        $encoded = (new PullCursor($this->hospital->id, 42, $this->device->device_uuid))->encode();

        $sameHospital = PullCursor::decode($encoded, $this->hospital->id, $this->device->device_uuid);
        $otherHospital = PullCursor::decode($encoded, $this->hospital->id + 999, $this->device->device_uuid);

        $this->assertSame(42, $sameHospital->revision);
        $this->assertSame(0, $otherHospital->revision);
    }

    // ── Scope ────────────────────────────────────────────────────────────

    public function test_a_device_never_receives_another_hospitals_records(): void
    {
        $theirs = Hospital::factory()->create();
        app(CurrentHospital::class)->set($theirs->id);
        Patient::factory()->create(['hospital_id' => $theirs->id, 'first_name' => 'TheirPatient']);
        app(CurrentHospital::class)->set($this->hospital->id);

        Patient::factory()->create(['hospital_id' => $this->hospital->id, 'first_name' => 'OurPatient']);

        $names = array_column(array_column($this->pull()->json('data.changes'), 'record'), 'first_name');

        $this->assertContains('OurPatient', $names);
        $this->assertNotContains('TheirPatient', $names);
    }

    public function test_a_role_without_inpatient_rights_gets_no_bedside_records(): void
    {
        $admission = $this->admission();
        app(\App\Services\Sync\PullService::class);

        // Vitals exist and the nurse can see them.
        $nurseEntities = array_column($this->pull()->json('data.changes'), 'entity');
        $this->assertContains('admissions', $nurseEntities);

        // Reception cannot. The smallest defensible copy of patient data is
        // the one that is not on the device at all (plan §17).
        $clerk = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'receptionist']);
        $clerk->syncSpatieRole();
        $clerkDevice = Device::create([
            'hospital_id' => $this->hospital->id, 'user_id' => $clerk->id,
            'device_uuid' => (string) Str::uuid(), 'label' => 'Desk', 'registered_at' => now(),
        ]);
        Sanctum::actingAs($clerk);

        $clerkEntities = array_column($this->pull(null, $clerkDevice)->json('data.changes'), 'entity');

        $this->assertNotContains('admissions', $clerkEntities);
        $this->assertNotContains('vitals', $clerkEntities);
        $this->assertContains('patients', $clerkEntities);
    }

    public function test_an_unregistered_device_cannot_pull(): void
    {
        $this->withHeader('X-Device-Id', (string) Str::uuid())
            ->getJson(route('api.sync.pull'))
            ->assertForbidden();
    }

    public function test_pulling_without_a_device_at_all_is_refused(): void
    {
        $this->getJson(route('api.sync.pull'))->assertForbidden();
    }

    // ── Tombstones ───────────────────────────────────────────────────────

    public function test_an_archived_patient_is_sent_as_a_tombstone_not_omitted(): void
    {
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'first_name' => 'Leaving']);
        $cursor = $this->pull()->json('data.next_cursor');

        $patient->delete();

        $changes = $this->pull($cursor)->json('data.changes');

        // Omitting it would leave the device holding the record for ever, with
        // nothing to say it had gone (plan §10.2).
        $this->assertCount(1, $changes);
        $this->assertSame($patient->uuid, $changes[0]['record']['uuid']);
        $this->assertNotNull($changes[0]['record']['deleted_at']);
    }

    // ── Acknowledgement ──────────────────────────────────────────────────

    public function test_acknowledging_records_where_the_device_got_to(): void
    {
        $this->patients(2);
        $cursor = $this->pull()->json('data.next_cursor');

        $this->withHeader('X-Device-Id', $this->device->device_uuid)
            ->postJson(route('api.sync.ack'), ['cursor' => $cursor, 'applied' => 2])
            ->assertOk();

        $this->assertSame($cursor, $this->device->fresh()->pull_cursor);
        $this->assertNotNull($this->device->fresh()->last_sync_at);
    }

    /**
     * The server records where a device SAYS it got to; it never decides.
     *
     * A server that advanced the cursor itself would skip a page the device
     * failed to commit — the exact loss invariant I-4 exists to prevent.
     */
    public function test_pulling_does_not_move_the_device_on_by_itself(): void
    {
        $this->patients(3);

        $this->pull();
        $this->pull();

        $this->assertNull($this->device->fresh()->pull_cursor);
        // …and a second pull with no cursor still returns everything, because
        // the device never told anybody it had saved the first one.
        $this->assertCount(3, $this->pull()->json('data.changes'));
    }

    // ── Reference data ───────────────────────────────────────────────────

    public function test_reference_data_carries_what_a_form_needs_and_no_balances(): void
    {
        $ward = Ward::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Maternity']);
        Bed::factory()->create(['hospital_id' => $this->hospital->id, 'ward_id' => $ward->id, 'name' => 'M-01']);

        $data = $this->withHeader('X-Device-Id', $this->device->device_uuid)
            ->getJson(route('api.sync.reference'))->assertOk()->json('data');

        $this->assertSame('Maternity', $data['reference']['wards'][0]['name']);
        $this->assertSame('M-01', $data['reference']['beds'][0]['name']);
        $this->assertNotNull($data['reference_version']);

        // A nurse gets no formulary, and nothing anywhere carries a stock
        // BALANCE — a device showing a stale one tells a pharmacist something
        // untrue (plan §4.5).
        $this->assertArrayNotHasKey('medications', $data['reference']);
        $this->assertStringNotContainsString('current_quantity', json_encode($data['reference']));
    }

    // ── Integrity across a full round ────────────────────────────────────

    public function test_nothing_is_delivered_twice_across_many_pages(): void
    {
        $this->patients(450);

        $seen = [];
        $cursor = null;
        $rounds = 0;

        do {
            $data = $this->pull($cursor)->assertOk()->json('data');

            foreach ($data['changes'] as $change) {
                $uuid = $change['record']['uuid'];
                $this->assertArrayNotHasKey($uuid, $seen, "Record {$uuid} was delivered twice.");
                $seen[$uuid] = true;
            }

            $cursor = $data['next_cursor'];
            $rounds++;
        } while ($data['has_more'] && $rounds < 20);

        $this->assertFalse($data['has_more']);
        $this->assertCount(450, $seen);
        $this->assertSame(450, DB::table('patients')->count());
    }

    private function admission(): \App\Models\Admission
    {
        $ward = Ward::factory()->create(['hospital_id' => $this->hospital->id]);
        $bed = Bed::factory()->create(['hospital_id' => $this->hospital->id, 'ward_id' => $ward->id]);
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);

        return app(AdmissionService::class)->admit($patient, $bed, [], $this->nurse->id);
    }

    /** A patient admitted long ago still reaches the ward tablet — it has to name the patient in the bed. */
    public function test_an_admitted_patient_is_on_the_device_however_long_ago_they_came(): void
    {
        $ward = \App\Models\Ward::factory()->create(['hospital_id' => $this->hospital->id]);
        $bed = \App\Models\Bed::factory()->create(['hospital_id' => $this->hospital->id, 'ward_id' => $ward->id]);
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        app(\App\Services\AdmissionService::class)->admit($patient, $bed, [], $this->nurse->id);
        // Nothing about them has changed for months.
        Patient::whereKey($patient->id)->update(['updated_at' => now()->subYear()]);
        \App\Models\Visit::where('patient_id', $patient->id)->update(['status' => 'completed', 'outcome' => 'closed', 'updated_at' => now()->subYear()]);

        $uuids = collect($this->pull()->assertOk()->json('data.changes'))->where('entity', 'patients')->pluck('record.uuid');

        $this->assertContains($patient->uuid, $uuids->all());
    }
}
