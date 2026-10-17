<?php

namespace Tests\Feature;

use App\Enums\DataExportStatus;
use App\Enums\DataRequestStatus;
use App\Jobs\BuildHospitalExport;
use App\Livewire\DataRequests\Index as DataRequestsIndex;
use App\Livewire\Settings\DataProtection;
use App\Models\DataExport;
use App\Models\DataRequest;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\PatientCard;
use App\Models\User;
use App\Models\Visit;
use App\Services\Compliance\DataRequestService;
use App\Services\Compliance\HospitalExport;
use App\Support\Compliance\ComplianceDocuments;
use App\Support\CurrentHospital;
use App\Support\Edition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;
use ZipArchive;

/**
 * Data protection (docs/compliance/README.md): the Act's documents, patients'
 * requests and their data, and a hospital's full export.
 */
class DataProtectionTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Hospital $hospital;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hospital = $this->hospital(['name' => 'Mukono Family Clinic']);
        $this->admin = $this->actingAsRole('hospital_admin', $this->hospital);
    }

    private function patient(array $attributes = [], ?Hospital $hospital = null): Patient
    {
        return Patient::factory()->create(array_merge(['hospital_id' => ($hospital ?? $this->hospital)->id], $attributes));
    }

    // ── The documents ────────────────────────────────────────────────────

    public function test_every_document_prints_filled_in_with_the_clinic(): void
    {
        foreach (array_keys(ComplianceDocuments::available()) as $key) {
            $response = $this->get(route('admin.compliance.document', $key));
            $response->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('content-type'), $key);
            $this->assertStringStartsWith('inline', (string) $response->headers->get('content-disposition'), $key);

            $html = ComplianceDocuments::html($key, $this->hospital);
            $this->assertStringNotContainsString('{clinic}', $html, $key);
            $this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', $html, "{$key} has a placeholder left");
        }

        $this->assertStringContainsString('Mukono Family Clinic', ComplianceDocuments::html('privacy-notice', $this->hospital));
        $this->assertStringContainsString('<table>', ComplianceDocuments::html('retention-schedule', $this->hospital));
    }

    public function test_a_clinic_name_cannot_inject_markup_into_a_document(): void
    {
        $this->hospital->forceFill(['name' => '<script>x</script> *Bold* Clinic'])->save();

        $html = ComplianceDocuments::html('privacy-notice', $this->hospital);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<em>Bold</em>', $html);
    }

    public function test_the_processing_agreement_is_only_offered_on_the_online_service(): void
    {
        config(['edition.name' => Edition::STANDALONE]);

        $this->assertArrayNotHasKey('data-processing-agreement', ComplianceDocuments::available());
        $this->assertFalse(ComplianceDocuments::exists('data-processing-agreement'));
        $this->assertTrue(ComplianceDocuments::exists('privacy-notice'));
    }

    public function test_an_unknown_document_is_not_found_and_a_doctor_cannot_open_any(): void
    {
        $this->get(route('admin.compliance.document', 'no-such-thing'))->assertNotFound();

        $this->actingAsRole('doctor', $this->hospital);
        $this->get(route('admin.compliance.document', 'privacy-notice'))->assertForbidden();
        $this->get(route('admin.settings.data-protection'))->assertForbidden();
        $this->get(route('admin.data-requests.index'))->assertForbidden();
    }

    public function test_accepting_the_agreement_records_the_version_the_date_and_who(): void
    {
        Livewire::test(DataProtection::class)
            ->assertSee('has not accepted the data processing agreement')
            ->call('acceptAgreement')
            ->assertSee('accepted by '.$this->admin->name);

        $dpa = $this->hospital->fresh()->settings['data_protection']['dpa'];
        $this->assertCount(1, $dpa);
        $this->assertSame(config('compliance.dpa_version'), $dpa[0]['version']);
        $this->assertSame($this->admin->id, $dpa[0]['user_id']);

        // Accepting the same version again adds nothing; a new version is asked for again.
        Livewire::test(DataProtection::class)->call('acceptAgreement');
        $this->assertCount(1, $this->hospital->fresh()->settings['data_protection']['dpa']);

        config(['compliance.dpa_version' => '2027-01']);
        Livewire::test(DataProtection::class)->assertSee('A new version')->call('acceptAgreement');
        $this->assertCount(2, $this->hospital->fresh()->settings['data_protection']['dpa']);
        $this->assertTrue(Activity::query()->where('description', 'data processing agreement accepted')->where('hospital_id', $this->hospital->id)->exists());
    }

    // ── Patients' requests ───────────────────────────────────────────────

    public function test_a_request_is_logged_with_its_due_date_and_closed_once(): void
    {
        $patient = $this->patient(['first_name' => 'Amina', 'last_name' => 'Nakato']);

        Livewire::test(DataRequestsIndex::class)
            ->call('create')
            ->call('picked', 'patient_id', $patient->id)
            ->assertSet('requester_name', 'Amina Nakato')
            ->set('request_kind', 'access')
            ->set('received_on', now()->subDays(2)->toDateString())
            ->set('details', 'Wants a copy before a referral.')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Amina Nakato');

        $request = DataRequest::query()->sole();
        $this->assertSame(DataRequestStatus::Open, $request->status);
        $this->assertSame(now()->subDays(2)->addDays(30)->toDateString(), $request->due_on->toDateString());
        $this->assertSame($this->admin->id, $request->recorded_by);

        Livewire::test(DataRequestsIndex::class)
            ->call('peek', $request->id)
            ->assertSee('Their data (PDF)')
            ->call('answer')
            ->assertHasErrors('outcome')
            ->set('outcome', 'Printed and handed over.')
            ->call('answer')
            ->assertHasNoErrors();

        $request->refresh();
        $this->assertSame(DataRequestStatus::Done, $request->status);
        $this->assertNotNull($request->closed_at);

        $this->expectException(ValidationException::class);
        app(DataRequestService::class)->close($request, DataRequestStatus::Refused, 'Changed my mind', $this->admin);
    }

    public function test_a_request_must_say_who_asked_and_cannot_be_dated_tomorrow(): void
    {
        Livewire::test(DataRequestsIndex::class)
            ->call('create')
            ->set('received_on', now()->addDay()->toDateString())
            ->call('save')
            ->assertHasErrors(['requester_name', 'received_on']);

        $this->assertSame(0, DataRequest::query()->count());
    }

    public function test_an_open_request_past_its_date_is_overdue_and_listed_first(): void
    {
        $service = app(DataRequestService::class);
        $old = $service->log(['requester_name' => 'Peter Okello', 'kind' => 'correction', 'received_on' => now()->subDays(40)->toDateString()], $this->admin);
        $closed = $service->log(['requester_name' => 'Sarah Achieng', 'kind' => 'objection', 'received_on' => now()->subDays(50)->toDateString()], $this->admin);
        $service->close($closed, DataRequestStatus::Done, 'Consent switched off.', $this->admin);

        $this->assertTrue($old->isOverdue());
        $this->assertFalse($closed->fresh()->isOverdue());

        Livewire::test(DataRequestsIndex::class)->assertSeeInOrder(['Peter Okello', 'Overdue', 'Sarah Achieng']);
    }

    public function test_another_hospitals_requests_and_patients_are_out_of_reach(): void
    {
        $other = $this->hospital();
        $theirPatient = $this->patient([], $other);
        app(CurrentHospital::class)->within($other->id, fn () => app(DataRequestService::class)
            ->log(['requester_name' => 'Somebody Else', 'kind' => 'access', 'received_on' => today()->toDateString()], $this->admin));

        Livewire::test(DataRequestsIndex::class)->assertDontSee('Somebody Else');
        $this->get(route('admin.patients.personal-data', ['patient' => $theirPatient, 'format' => 'pdf']))->assertNotFound();
        Livewire::test(DataRequestsIndex::class)->call('create')->set('patient_id', $theirPatient->id)
            ->set('requester_name', 'X')->call('save')->assertHasErrors('patient_id');
    }

    public function test_another_hospitals_request_cannot_be_opened_from_the_dialog(): void
    {
        $other = $this->hospital();
        $theirs = app(CurrentHospital::class)->within($other->id, fn () => app(DataRequestService::class)
            ->log(['requester_name' => 'Somebody Else', 'kind' => 'access', 'received_on' => today()->toDateString()], $this->admin));

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test(DataRequestsIndex::class)->call('peek', $theirs->id);
    }

    public function test_a_platform_account_outside_a_hospital_cannot_read_every_clinics_requests(): void
    {
        $this->actingAsSuperAdmin();

        $this->get(route('admin.data-requests.index'))->assertForbidden();
    }

    // ── One patient's data ───────────────────────────────────────────────

    public function test_a_patients_data_holds_their_record_and_nobody_elses(): void
    {
        $patient = $this->patient(['first_name' => 'Amina', 'last_name' => 'Nakato', 'bank_details' => 'Stanbic 9030012345']);
        $someoneElse = $this->patient(['first_name' => 'Joseph', 'last_name' => 'Wasswa']);
        Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $patient->id, 'complaints' => 'Fever for three days']);
        Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $someoneElse->id, 'complaints' => 'Twisted ankle']);

        $json = $this->get(route('admin.patients.personal-data', ['patient' => $patient, 'format' => 'json']))->assertOk()->getContent();

        $this->assertStringContainsString('Amina', (string) $json);
        $this->assertStringContainsString('Fever for three days', (string) $json);
        $this->assertStringContainsString('Stanbic 9030012345', (string) $json, 'bank details are decrypted for the patient');
        $this->assertStringNotContainsString('Twisted ankle', (string) $json);
        $this->assertStringNotContainsString('Wasswa', (string) $json);

        $pdf = $this->get(route('admin.patients.personal-data', ['patient' => $patient, 'format' => 'pdf']));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
        $this->assertTrue(Activity::query()->where('description', 'patient data exported')->where('subject_id', $patient->id)->exists());
    }

    // ── A hospital's full export ─────────────────────────────────────────

    public function test_asking_for_an_export_queues_one_build_at_a_time(): void
    {
        Queue::fake();

        Livewire::test(DataProtection::class)->call('requestExport')->assertSee('Waiting to start');
        Queue::assertPushedOn('exports', BuildHospitalExport::class);
        $this->assertSame(1, DataExport::query()->count());

        Livewire::test(DataProtection::class)->call('requestExport');
        $this->assertSame(1, DataExport::query()->count(), 'a second export waits for the first');
        Queue::assertPushed(BuildHospitalExport::class, 1);
    }

    public function test_the_export_holds_this_hospitals_rows_and_files_and_no_secrets(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Queue::fake();

        $mine = $this->patient(['first_name' => 'Amina', 'last_name' => 'Nakato', 'bank_details' => 'Stanbic 9030012345']);
        $other = $this->hospital();
        $theirs = $this->patient(['first_name' => 'Hidden', 'last_name' => 'Elsewhere'], $other);
        PatientCard::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $mine->id, 'card_number' => 'TD-0001-7777']);
        Storage::disk('local')->put('patients/'.$this->hospital->id.'/'.$mine->id.'/scan.pdf', 'PDF-BYTES');
        \App\Models\PatientDocument::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'patient_id' => $mine->id, 'type' => 'other', 'file_path' => 'patients/'.$this->hospital->id.'/'.$mine->id.'/scan.pdf', 'original_name' => 'scan.pdf', 'mime' => 'application/pdf', 'size' => 9]);

        $export = app(HospitalExport::class)->request($this->admin);
        // What the worker does: no tenant in the process.
        app(CurrentHospital::class)->set(null);
        (new BuildHospitalExport($export->id))->handle(app(HospitalExport::class));
        app(CurrentHospital::class)->set($this->hospital->id);

        $export->refresh();
        $this->assertSame(DataExportStatus::Ready, $export->status);
        $this->assertSame(1, $export->files_count);
        $this->assertTrue($export->expires_at->isFuture());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path((string) $export->path)));
        $patients = (string) $zip->getFromName('tables/patients.csv');
        $users = (string) $zip->getFromName('tables/users.csv');
        $cards = (string) $zip->getFromName('tables/patient_cards.csv');

        $this->assertStringContainsString('Amina', $patients);
        $this->assertStringContainsString('Stanbic 9030012345', $patients, 'encrypted columns leave readable');
        $this->assertStringNotContainsString('Hidden', $patients, 'another hospital\'s rows never leave');
        $this->assertStringNotContainsString((string) $theirs->uuid, $patients);
        $this->assertStringContainsString('TD-0001-7777', $cards);
        $this->assertStringContainsString($this->admin->email, $users);
        $head = str_getcsv((string) strtok($users, "\n"));
        $this->assertNotContains('password', $head);
        $this->assertNotContains('two_factor_secret', $head);
        $this->assertNotContains('remember_token', $head);
        $this->assertSame('PDF-BYTES', $zip->getFromName('files/local/patients/'.$this->hospital->id.'/'.$mine->id.'/scan.pdf'));
        $this->assertStringContainsString('users,password,', (string) $zip->getFromName('_columns.csv'));
        $this->assertStringContainsString('left out: secret', (string) $zip->getFromName('_columns.csv'));
        $this->assertNotFalse($zip->getFromName('README.txt'));
        $this->assertFalse($zip->getFromName('tables/sync_operations.csv'));
        $zip->close();
    }

    public function test_the_download_link_is_encrypted_expires_and_only_opens_for_the_same_hospital(): void
    {
        Storage::fake('local');
        Queue::fake();
        $export = app(HospitalExport::class)->request($this->admin);
        app(HospitalExport::class)->build($export);
        $export->refresh();
        $link = app(HospitalExport::class)->link($export);

        $this->assertStringNotContainsString($export->uuid, $link, 'the link does not name the export in the clear');

        $this->get($link)->assertOk()->assertHeader('content-type', 'application/zip');
        $this->assertSame(1, $export->fresh()->download_count);
        $this->assertTrue(Activity::query()->where('description', 'data export downloaded')->where('subject_id', $export->id)->exists());

        // A tampered token, another hospital's admin, a doctor: all nothing.
        $this->get(route('admin.data-exports.download', ['token' => 'not-a-token']))->assertNotFound();
        $this->actingAsRole('hospital_admin', $this->hospital());
        $this->get($link)->assertNotFound();
        $this->actingAsRole('doctor', $this->hospital);
        $this->get($link)->assertForbidden();

        // After it expires, the link is dead and the prune deletes the file.
        $this->actingAs($this->admin);
        app(CurrentHospital::class)->set($this->hospital->id);
        $this->travel((int) config('compliance.export_hours') + 1)->hours();
        $this->get($link)->assertNotFound();
        $this->assertSame(1, app(HospitalExport::class)->prune());
        $this->assertSame(DataExportStatus::Expired, $export->fresh()->status);
        Storage::disk('local')->assertMissing((string) $export->path);
    }

    public function test_a_token_for_a_live_export_with_a_forged_expiry_is_refused(): void
    {
        Storage::fake('local');
        Queue::fake();
        $export = app(HospitalExport::class)->request($this->admin);
        app(HospitalExport::class)->build($export);

        $stale = Crypt::encryptString((string) json_encode(['e' => $export->uuid, 'x' => now()->subMinute()->getTimestamp()]));

        $this->get(route('admin.data-exports.download', ['token' => $stale]))->assertNotFound();
    }

    /**
     * The secret columns are left out by name, so a new secret column would
     * leave unnoticed. This fails first: name it in TenantTables::OMIT, or
     * here if it is not a secret.
     */
    public function test_no_exported_column_looks_like_a_secret(): void
    {
        $tables = app(\App\Support\Compliance\TenantTables::class);
        $allowed = ['users.password_change_required', 'patient_cards.card_number'];
        $offenders = [];

        foreach ($tables->withColumn('hospital_id') as $table) {
            foreach ($tables->exportable($table) as $column) {
                if (preg_match('/secret|token|password|nonce|hash|api_?key|pin$/i', $column) && ! in_array($table.'.'.$column, $allowed, true)) {
                    $offenders[] = $table.'.'.$column;
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_the_prune_clears_files_no_ready_export_points_at(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('exports/999/left-behind.zip', 'x');
        touch(Storage::disk('local')->path('exports/999/left-behind.zip'), now()->subHours(4)->getTimestamp());
        Storage::disk('local')->put('exports/999/fresh.zip', 'x');

        app(HospitalExport::class)->prune();

        Storage::disk('local')->assertMissing('exports/999/left-behind.zip');
        Storage::disk('local')->assertExists('exports/999/fresh.zip');
    }

    public function test_a_platform_account_cannot_ask_for_a_hospitals_export(): void
    {
        $super = $this->actingAsSuperAdmin();
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->expectException(ValidationException::class);
        app(HospitalExport::class)->request($super);
    }

    public function test_the_data_protection_page_lists_documents_and_exports(): void
    {
        $this->get(route('admin.settings.data-protection'))->assertOk()
            ->assertSee('Privacy notice for patients')
            ->assertSee('Export all your data')
            ->assertSee('No exports yet.');
    }

    // ── Demo ─────────────────────────────────────────────────────────────

    public function test_the_demo_scenario_adds_requests_once(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->seed(\Database\Seeders\DemoSeeder::class);
        $demo = Hospital::where('slug', 'general-hospital-a')->firstOrFail();

        \Database\Seeders\DemoScenarios\Compliance::run($demo);
        $count = DataRequest::query()->withoutGlobalScopes()->where('hospital_id', $demo->id)->count();
        \Database\Seeders\DemoScenarios\Compliance::run($demo);

        $this->assertGreaterThanOrEqual(3, $count);
        $this->assertSame($count, DataRequest::query()->withoutGlobalScopes()->where('hospital_id', $demo->id)->count());
        $this->assertCount(1, $demo->fresh()->settings['data_protection']['dpa']);
        $this->assertTrue(DataRequest::query()->withoutGlobalScopes()->where('hospital_id', $demo->id)->where('status', 'open')->exists());
    }
}
