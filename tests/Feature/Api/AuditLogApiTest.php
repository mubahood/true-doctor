<?php

namespace Tests\Feature\Api;

use App\Models\AuditEntry;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Services\Audit\RecordAccessLog;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The audit log's two tabs on the phone (docs/api/audit.md): changes with the
 * web's record-number search and the change field by field, records opened
 * with the web's filters, and the filter lists. `manage-settings`, one hospital.
 */
class AuditLogApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    private Hospital $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->h = Hospital::factory()->create();
        $this->other = Hospital::factory()->create();
        $this->admin = $this->user('hospital_admin', $this->h, ['name' => 'Grace Owner']);
    }

    private function user(string $role, Hospital $h, array $attrs = []): User
    {
        $u = User::factory()->create($attrs + ['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function patient(Hospital $h, array $attrs = []): Patient
    {
        app(CurrentHospital::class)->set($h->id);

        return Patient::factory()->create($attrs + ['hospital_id' => $h->id]);
    }

    public function test_the_record_number_search_and_one_change_field_by_field(): void
    {
        Sanctum::actingAs($this->admin);
        $amina = $this->patient($this->h, ['first_name' => 'Amina']);
        $joseph = $this->patient($this->h, ['first_name' => 'Joseph']);
        $this->actingAs($this->admin);
        $amina->update(['first_name' => 'Aminah']);
        $joseph->update(['first_name' => 'Joe']);

        $rows = $this->getJson("/api/v1/audit-log?type=patient&q={$amina->id}")->assertOk()->json('data');
        $this->assertNotEmpty($rows);
        $this->assertSame([$amina->id], array_values(array_unique(array_column($rows, 'record_id'))), 'only the record asked for');

        $change = collect($rows)->firstWhere('event', 'updated');
        $this->assertSame('Changed', $change['event_label']);
        $this->assertSame('First Name', $change['summary']);
        $this->assertSame('Grace Owner', $change['who']);

        $one = $this->getJson("/api/v1/audit-log/{$change['id']}")->assertOk()->json('data');
        $this->assertSame([['field' => 'First Name', 'old' => 'Amina', 'new' => 'Aminah']], $one['fields']);
        $this->assertSame('Patient', $one['type_label']);
        $this->assertArrayHasKey('via_label', $one);
        $this->assertArrayHasKey('ip', $one);
    }

    public function test_another_hospitals_change_is_not_found_and_never_listed(): void
    {
        $foreign = $this->patient($this->other, ['first_name' => 'Elsewhere']);
        $entry = AuditEntry::withoutGlobalScopes()->where('hospital_id', $this->other->id)->latest('id')->firstOrFail();

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/v1/audit-log/{$entry->id}")->assertNotFound();
        $this->assertStringNotContainsString('Elsewhere', (string) $this->getJson('/api/v1/audit-log?q='.$foreign->id)->getContent());
    }

    public function test_records_opened_with_the_webs_filters(): void
    {
        $doctor = $this->user('doctor', $this->h, ['name' => 'Dr Okello']);
        $amina = $this->patient($this->h, ['first_name' => 'Amina', 'last_name' => 'Nakato']);
        $joseph = $this->patient($this->h, ['first_name' => 'Joseph']);
        $log = app(RecordAccessLog::class);
        $log->opened($amina, $doctor);
        $log->printed($amina, $this->admin, 'Statement of account');
        $log->opened($joseph, $this->admin);
        $foreign = $this->patient($this->other);
        $log->opened($foreign, $this->user('doctor', $this->other));

        Sanctum::actingAs($this->admin);
        $all = $this->getJson('/api/v1/audit-log/opens')->assertOk()->assertJsonPath('meta.total', 3)->json('data');
        $this->assertSame(['id', 'at', 'action', 'action_label', 'what', 'who', 'role', 'channel', 'device', 'ip', 'patient'], array_keys($all[0]));

        $amina2 = $this->getJson("/api/v1/audit-log/opens?patient={$amina->uuid}")->assertOk()->json('data');
        $this->assertCount(2, $amina2);
        $this->assertSame('Amina Nakato', $amina2[0]['patient']['name']);

        $this->assertSame([$joseph->uuid], array_column(array_column($this->getJson('/api/v1/audit-log/opens?q=Joseph')->json('data'), 'patient'), 'uuid'), 'the phone searches by patient');

        $printed = $this->getJson('/api/v1/audit-log/opens?action=printed')->json('data');
        $this->assertSame(['Statement of account'], array_column($printed, 'what'));
        $this->assertSame(['Dr Okello'], array_column($this->getJson("/api/v1/audit-log/opens?user_id={$doctor->id}")->json('data'), 'who'));

        // An archived patient's opens are still theirs, never everybody's.
        $joseph->delete();
        $this->assertSame([$joseph->uuid], array_column(array_column($this->getJson("/api/v1/audit-log/opens?patient={$joseph->uuid}")->json('data'), 'patient'), 'uuid'));

        $this->getJson("/api/v1/audit-log/opens?patient={$foreign->uuid}")->assertStatus(422)
            ->assertJsonPath('errors.patient.0', 'That patient is not on this hospital\'s register.');
        $this->getJson('/api/v1/audit-log/opens?action=deleted')->assertStatus(422)->assertJsonValidationErrors('action');
        $this->getJson('/api/v1/audit-log/opens?from=yesterday')->assertStatus(422)->assertJsonValidationErrors('from');
    }

    public function test_the_filter_lists_are_the_webs_with_this_hospitals_people(): void
    {
        $this->user('nurse', $this->h, ['name' => 'Left Already', 'is_active' => false]);
        $this->user('nurse', $this->other, ['name' => 'Someone Else']);

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/audit-log/filters')->assertOk()->json('data');

        $this->assertContains(['value' => 'patient', 'label' => 'Patient'], $data['types']);
        $this->assertSame([['value' => 'created', 'label' => 'Created'], ['value' => 'updated', 'label' => 'Changed'], ['value' => 'deleted', 'label' => 'Deleted']], $data['events']);
        $this->assertSame(['opened', 'printed'], array_column($data['actions'], 'value'));
        $this->assertSame(['Grace Owner', 'Left Already'], array_column($data['people'], 'name'), 'people who left stay, other hospitals never show');
        $this->assertFalse($data['people'][1]['is_active']);
    }

    public function test_only_who_runs_the_settings(): void
    {
        $this->getJson('/api/v1/audit-log/opens')->assertUnauthorized();

        Sanctum::actingAs($this->user('doctor', $this->h));
        $this->getJson('/api/v1/audit-log/opens')->assertForbidden();
        $this->getJson('/api/v1/audit-log/filters')->assertForbidden();
        $this->getJson('/api/v1/audit-log/1')->assertForbidden();
    }

    public function test_a_platform_account_outside_a_hospital_never_reads_every_clinics_log(): void
    {
        $this->patient($this->h);
        app(RecordAccessLog::class)->opened($this->patient($this->h), $this->admin);
        $super = User::factory()->create(['hospital_id' => null, 'role' => 'super_admin', 'is_admin' => true]);
        $super->syncSpatieRole();
        app(CurrentHospital::class)->set(null);
        Sanctum::actingAs($super);

        $this->getJson('/api/v1/audit-log')->assertForbidden();
        $this->getJson('/api/v1/audit-log/opens')->assertForbidden();
        $this->getJson('/api/v1/audit-log/filters')->assertForbidden();
        $this->getJson('/api/v1/admin/access')->assertForbidden();
    }
}
