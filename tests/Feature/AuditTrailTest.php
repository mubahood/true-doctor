<?php

namespace Tests\Feature;

use App\Livewire\Audit\Index as AuditIndex;
use App\Livewire\Patients\Tabs\Access as AccessTab;
use App\Livewire\Visits\Panels\Clinical;
use App\Models\AuditEntry;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\RecordAccess;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\Audit\AuditTrail;
use App\Services\Audit\RecordAccessLog;
use App\Support\CurrentHospital;
use Database\Seeders\DemoScenarios\Audit as AuditScenario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use LogicException;
use Tests\Concerns\BuildsPatientRecords;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/** Write audit, read audit and encrypted fields (roadmap 07 §3, docs/audit.md). */
class AuditTrailTest extends TestCase
{
    use BuildsPatientRecords, InteractsWithTenant, RefreshDatabase;

    // ── Write audit ──────────────────────────────────────────────────────

    public function test_every_listed_model_is_observed(): void
    {
        foreach (array_keys(AuditTrail::MODELS) as $class) {
            $this->assertTrue($class::getEventDispatcher()->hasListeners("eloquent.updated: {$class}"), "{$class} is not audited");
        }
    }

    public function test_a_change_records_who_when_and_each_field_before_and_after(): void
    {
        $h = $this->hospital();
        $admin = $this->actingAsRole('hospital_admin', $h);
        $p = $this->patientOf($h, ['first_name' => 'Amina', 'phone_1' => '0700111222']);

        $p->update(['first_name' => 'Aminah', 'phone_1' => '0700111223']);

        $entry = AuditEntry::where('subject_type', $p->getMorphClass())->where('subject_id', $p->id)->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame('patient', $entry->log_name);
        $this->assertSame($h->id, $entry->hospital_id);
        $this->assertSame($admin->id, (int) $entry->causer_id);
        $this->assertSame(['first_name' => 'Aminah', 'phone_1' => '0700111223'], $entry->newValues());
        $this->assertSame(['first_name' => 'Amina', 'phone_1' => '0700111222'], $entry->oldValues());
    }

    public function test_money_and_price_changes_are_audited(): void
    {
        $h = $this->hospital();
        $admin = $this->actingAsRole('hospital_admin', $h);
        $p = $this->patientOf($h);
        $invoice = $this->invoiceFor($p, '25000.00', null, $admin->id);
        $this->pay($invoice, '10000.00', $admin->id);

        $this->assertTrue(AuditEntry::where('log_name', 'invoice')->where('event', 'created')->exists());
        $this->assertTrue(AuditEntry::where('log_name', 'payment')->where('event', 'created')->exists());
        $this->assertTrue(AuditEntry::where('log_name', 'visit')->where('event', 'created')->exists());

        $service = Service::factory()->create(['hospital_id' => $h->id, 'price' => '18000.00']);
        $service->update(['price' => '20000.00']);
        $entry = AuditEntry::where('log_name', 'service')->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame('18000.00', $entry->oldValues()['price']);
        $this->assertSame('20000.00', $entry->newValues()['price']);
    }

    public function test_secrets_and_encrypted_fields_are_masked(): void
    {
        $h = $this->hospital();
        $admin = $this->actingAsRole('hospital_admin', $h);
        $p = $this->patientOf($h);

        $p->update(['notes' => 'HIV positive, on ART']);
        $admin->update(['password' => 'N3w-passw0rd-long']);

        $notes = AuditEntry::where('log_name', 'patient')->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame(AuditTrail::MASK, $notes->newValues()['notes']);
        $this->assertNull($notes->oldValues()['notes']);

        $pwd = AuditEntry::where('log_name', 'user')->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame(AuditTrail::MASK, $pwd->newValues()['password']);
        $this->assertSame(AuditTrail::MASK, $pwd->oldValues()['password']);

        $this->assertStringNotContainsString('HIV', (string) DB::table('activity_log')->pluck('properties')->implode(' '));
    }

    public function test_a_save_that_only_moves_bookkeeping_writes_nothing(): void
    {
        $h = $this->hospital();
        $this->actingAsRole('hospital_admin', $h);
        $p = $this->patientOf($h);
        $before = AuditEntry::count();

        $p->touch();

        $this->assertSame($before, AuditEntry::count());
    }

    public function test_the_trail_is_append_only(): void
    {
        $h = $this->hospital();
        $this->actingAsRole('hospital_admin', $h);
        $this->patientOf($h);
        $entry = AuditEntry::firstOrFail();

        try {
            $entry->update(['description' => 'nothing to see']);
            $this->fail('An audit entry was edited.');
        } catch (LogicException) {
        }
        try {
            $entry->delete();
            $this->fail('An audit entry was deleted.');
        } catch (LogicException) {
        }
        $this->assertSame('created', $entry->fresh()?->description);

        $access = app(RecordAccessLog::class)->opened(Patient::firstOrFail(), auth()->user());
        $this->expectException(LogicException::class);
        $access?->delete();
    }

    public function test_one_hospital_never_reads_anothers_trail(): void
    {
        $a = $this->hospital();
        $this->actingAsRole('hospital_admin', $a);
        $this->patientOf($a, ['first_name' => 'OnlyInA']);

        $b = $this->hospital();
        $this->actingAsRole('hospital_admin', $b);

        $this->assertSame(0, AuditEntry::where('log_name', 'patient')->count());
        Livewire::test(AuditIndex::class)->assertDontSee('OnlyInA')->assertDontSee('Patient #');
    }

    // ── Read audit ───────────────────────────────────────────────────────

    public function test_opening_a_patient_on_the_web_is_logged_once_per_window(): void
    {
        $h = $this->hospital();
        $doctor = $this->actingAsRole('doctor', $h);
        $p = $this->patientOf($h);

        $this->get("/admin/patients/{$p->uuid}")->assertOk();
        $this->get("/admin/patients/{$p->uuid}")->assertOk();

        $this->assertSame(1, RecordAccess::where('patient_id', $p->id)->count());
        $row = RecordAccess::firstOrFail();
        $this->assertSame(['opened', 'web', $doctor->id], [$row->action, $row->channel, $row->user_id]);

        $this->travel(RecordAccessLog::WINDOW_MINUTES + 1)->minutes();
        $this->get("/admin/patients/{$p->uuid}")->assertOk();
        $this->assertSame(2, RecordAccess::where('patient_id', $p->id)->count());
    }

    public function test_another_hospitals_patient_is_refused_and_not_logged(): void
    {
        $b = $this->hospital();
        $theirs = Patient::factory()->create(['hospital_id' => $b->id]);
        $this->actingAsRole('hospital_admin', $this->hospital());

        $this->get("/admin/patients/{$theirs->uuid}")->assertNotFound();
        $this->assertSame(0, RecordAccess::withoutGlobalScopes()->count());
    }

    public function test_the_app_opening_or_printing_a_record_is_logged(): void
    {
        $h = $this->hospital();
        $admin = $this->actingAsRole('hospital_admin', $h);
        $p = $this->patientOf($h);
        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/patients/{$p->uuid}", ['X-App-Version' => '9.9.9'])->assertOk();
        $this->getJson("/api/v1/patients/{$p->uuid}/summary")->assertOk();
        $this->get("/api/v1/patients/{$p->uuid}/statement")->assertOk();

        $rows = RecordAccess::where('patient_id', $p->id)->orderBy('id')->get();
        $this->assertSame(['opened', 'printed'], $rows->pluck('action')->all());
        $this->assertSame('api', $rows[0]->channel);
        $this->assertStringContainsString('9.9.9', (string) $rows[0]->device);
        $this->assertSame('Statement of account', $rows[1]->context);
    }

    // ── Encryption ───────────────────────────────────────────────────────

    public function test_sensitive_fields_are_unreadable_in_the_database_and_readable_through_the_model(): void
    {
        $h = $this->hospital();
        $this->actingAsRole('doctor', $h);
        $p = $this->patientOf($h, ['notes' => 'Known HIV status: positive']);
        $v = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $p->id, 'sensitive_notes' => 'Depression, on sertraline']);

        $this->assertStringNotContainsString('HIV', (string) DB::table('patients')->where('id', $p->id)->value('notes'));
        $this->assertStringNotContainsString('sertraline', (string) DB::table('visits')->where('id', $v->id)->value('sensitive_notes'));
        $this->assertSame('Known HIV status: positive', $p->fresh()?->notes);
        $this->assertSame('Depression, on sertraline', $v->fresh()?->sensitive_notes);
        $this->assertArrayNotHasKey('sensitive_notes', $v->fresh()?->toArray() ?? []);
    }

    public function test_the_migration_encrypts_existing_rows_once_and_is_safe_to_rerun(): void
    {
        $h = $this->hospital();
        $this->actingAsRole('hospital_admin', $h);
        $plain = $this->patientOf($h);
        $already = $this->patientOf($h, ['notes' => 'Already encrypted']);
        DB::table('patients')->where('id', $plain->id)->update(['notes' => 'Written before encryption']);

        $migration = require database_path('migrations/2026_10_22_150000_audit_trail_and_sensitive_fields.php');
        $migration->up();
        $migration->up();

        $raw = (string) DB::table('patients')->where('id', $plain->id)->value('notes');
        $this->assertSame('Written before encryption', Crypt::decryptString($raw));
        $this->assertSame('Already encrypted', $already->fresh()?->notes);
    }

    // ── Screens ──────────────────────────────────────────────────────────

    public function test_the_audit_log_is_the_owners_screen(): void
    {
        $h = $this->hospital();
        $this->actingAsRole('doctor', $h);
        $this->get('/admin/audit-log')->assertForbidden();

        $this->actingAsRole('hospital_admin', $h);
        $this->get('/admin/audit-log')->assertOk()->assertSee('Audit log');
    }

    public function test_the_audit_log_filters_and_shows_a_change_field_by_field(): void
    {
        $h = $this->hospital();
        $admin = $this->actingAsRole('hospital_admin', $h);
        $service = Service::factory()->create(['hospital_id' => $h->id, 'price' => '5000.00']);
        $service->update(['price' => '6000.00']);
        $p = $this->patientOf($h, ['first_name' => 'Zawadi']);
        app(RecordAccessLog::class)->opened($p, $admin);
        $entry = AuditEntry::where('log_name', 'service')->where('event', 'updated')->firstOrFail();

        Livewire::test(AuditIndex::class)
            ->set('type', 'service')
            ->assertSee('Price list item #'.$service->id)
            ->call('peek', $entry->id)
            ->assertSee('5000.00')
            ->assertSee('6000.00')
            ->set('view', 'opens')
            ->assertSee('Zawadi');
    }

    public function test_who_opened_this_record_is_shown_to_the_owner_only(): void
    {
        $h = $this->hospital();
        $doctor = $this->actingAsRole('doctor', $h);
        $p = $this->patientOf($h);
        app(RecordAccessLog::class)->opened($p, $doctor);

        Livewire::withoutLazyLoading()->test(AccessTab::class, ['patientId' => $p->id])->assertForbidden();

        $this->actingAsRole('hospital_admin', $h);
        Livewire::withoutLazyLoading()->test(AccessTab::class, ['patientId' => $p->id])->assertOk()->assertSee($doctor->name);
    }

    public function test_the_confidential_note_is_written_by_the_doctor_and_hidden_from_the_nurse(): void
    {
        $h = $this->hospital();
        $this->actingAsRole('doctor', $h);
        $p = $this->patientOf($h);
        $v = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $p->id]);

        Livewire::withoutLazyLoading()->test(Clinical::class, ['visitId' => $v->id])
            ->call('openForm')
            ->set('sensitive_notes', 'Disclosed intimate partner violence')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('Disclosed intimate partner violence', $v->fresh()?->sensitive_notes);

        $this->actingAsRole('nurse', $h);
        Livewire::withoutLazyLoading()->test(Clinical::class, ['visitId' => $v->id])
            ->assertDontSee('intimate partner')
            ->assertSee('A confidential note is on this visit.')
            ->assertSet('sensitive_notes', null);
    }

    // ── API ──────────────────────────────────────────────────────────────

    public function test_the_api_audit_log_answers_the_owner_and_refuses_others(): void
    {
        $this->getJson('/api/v1/audit-log')->assertUnauthorized();

        $h = $this->hospital();
        $doctor = $this->actingAsRole('doctor', $h);
        $p = $this->patientOf($h);
        Sanctum::actingAs($doctor);
        $this->getJson('/api/v1/audit-log')->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->getJson("/api/v1/patients/{$p->uuid}/access-log")->assertForbidden();

        $admin = $this->actingAsRole('hospital_admin', $h);
        app(RecordAccessLog::class)->opened($p, $doctor);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/audit-log?type=patient')->assertOk()
            ->assertJsonPath('data.0.type', 'patient')
            ->assertJsonStructure(['data' => [['id', 'at', 'type_label', 'record_id', 'event', 'who', 'via', 'fields']], 'meta' => ['total']]);
        $this->getJson('/api/v1/audit-log?event=nonsense')->assertStatus(422);
        $this->getJson("/api/v1/patients/{$p->uuid}/access-log")->assertOk()
            ->assertJsonPath('data.0.who', $doctor->name)
            ->assertJsonPath('data.0.action', 'opened');

        $other = Patient::factory()->create(['hospital_id' => $this->hospital()->id]);
        $this->getJson("/api/v1/patients/{$other->uuid}/access-log")->assertNotFound();
    }

    public function test_the_app_is_told_there_is_a_confidential_note_but_never_given_it(): void
    {
        $h = $this->hospital();
        $doctor = $this->actingAsRole('doctor', $h);
        $p = $this->patientOf($h);
        $v = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $p->id, 'sensitive_notes' => 'Self-harm risk']);

        Sanctum::actingAs($doctor);
        $res = $this->getJson("/api/v1/visits/{$v->uuid}")->assertOk()->assertJsonPath('data.has_sensitive_notes', true);
        $this->assertArrayNotHasKey('sensitive_notes', $res->json('data'));
        $this->assertStringNotContainsString('Self-harm', (string) $this->getJson('/api/v1/visits')->getContent());
    }

    public function test_reading_a_confidential_note_is_logged(): void
    {
        $h = $this->hospital();
        $doctor = $this->actingAsRole('doctor', $h);
        $p = $this->patientOf($h);
        $v = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $p->id, 'sensitive_notes' => 'Self-harm risk']);

        Livewire::withoutLazyLoading()->test(Clinical::class, ['visitId' => $v->id])->assertSee('Self-harm risk');

        $row = RecordAccess::where('patient_id', $p->id)->firstOrFail();
        $this->assertSame([$doctor->id, 'opened', 'Confidential note '.$v->visit_no], [$row->user_id, $row->action, $row->context]);
    }

    // ── Demo ─────────────────────────────────────────────────────────────

    public function test_the_demo_scenario_adds_opens_and_named_corrections_and_runs_twice_safely(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->seed(\Database\Seeders\DemoSeeder::class);
        $hospital = Hospital::where('slug', 'general-hospital-a')->firstOrFail();

        AuditScenario::run($hospital);
        app(CurrentHospital::class)->set($hospital->id);
        $opens = RecordAccess::count();
        $named = AuditEntry::whereNotNull('causer_id')->count();

        AuditScenario::run($hospital);

        $this->assertGreaterThan(0, $opens);
        $this->assertSame($opens, RecordAccess::count());
        $this->assertSame($named, AuditEntry::whereNotNull('causer_id')->count());
        // Every open is by this hospital's own staff, of this hospital's own patients.
        $this->assertSame(0, RecordAccess::whereHas('user', fn ($q) => $q->where('hospital_id', '!=', $hospital->id))->count());
        $this->assertSame(0, RecordAccess::whereDoesntHave('patient')->count());
        // A correction ends at the value the record holds today.
        $phone = AuditEntry::where('log_name', 'patient')->whereNotNull('causer_id')->first();
        if ($phone !== null) {
            $this->assertSame(Patient::find($phone->subject_id)?->phone_1, $phone->newValues()['phone_1']);
        }
        $this->assertInstanceOf(User::class, User::where('hospital_id', $hospital->id)->first());
    }
}
