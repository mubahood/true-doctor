<?php

namespace Tests\Feature\Api;

use App\Models\Hospital;
use App\Models\InsuranceProvider;
use App\Models\Patient;
use App\Models\PatientDependent;
use App\Models\PatientDocument;
use App\Models\PatientInsurance;
use App\Models\TreatmentRecord;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\PatientService;
use App\Services\TreatmentService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The patient record's panels over the API (docs/api/patient-records.md):
 * documents, insurance cover, dependents, treatment records with photos, and
 * the ID card. Same services and permissions as the web's panels; another
 * hospital's record is a 404; a repeated Idempotency-Key writes once.
 */
class PatientRecordsApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        Storage::fake('local');

        $this->h = Hospital::factory()->create();
        $this->patient = Patient::factory()->create(['hospital_id' => $this->h->id, 'first_name' => 'Amina', 'last_name' => 'Nakato']);
    }

    private function staff(string $role, ?Hospital $h = null): User
    {
        $h ??= $this->h;
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    private function otherHospitalPatient(): Patient
    {
        $other = Hospital::factory()->create();

        return Patient::factory()->create(['hospital_id' => $other->id]);
    }

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    // ── Documents ────────────────────────────────────────────────────────

    public function test_documents_are_listed_uploaded_downloaded_and_removed_through_the_service(): void
    {
        Sanctum::actingAs($this->staff('receptionist'));
        $base = "/api/v1/patients/{$this->patient->uuid}/documents";

        $this->getJson($base)->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.can_manage', true)
            ->assertJsonPath('meta.max_kb', 10240)
            ->assertJsonPath('meta.types.0', ['value' => 'id', 'label' => 'ID']);

        $made = $this->post($base, [
            'type' => 'referral',
            'note' => 'From the district hospital',
            'file' => UploadedFile::fake()->create('referral.pdf', 120, 'application/pdf'),
        ], ['Accept' => 'application/json'] + $this->key())->assertCreated()
            ->assertJsonPath('data.type', 'referral')
            ->assertJsonPath('data.type_label', 'Referral')
            ->assertJsonPath('data.original_name', 'referral.pdf')
            ->assertJsonPath('data.note', 'From the district hospital')
            ->json('data');

        $document = PatientDocument::where('uuid', $made['uuid'])->sole();
        Storage::disk('local')->assertExists($document->file_path);
        $this->assertStringStartsWith("patients/{$this->h->id}/{$this->patient->id}/", $document->file_path);
        $this->assertSame("patients/{$this->patient->uuid}/documents/{$document->uuid}/download", $made['download_path']);

        $this->getJson($base)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $document->uuid);

        $download = $this->get('/api/v1/'.$made['download_path'])->assertOk();
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('referral.pdf', (string) $download->headers->get('Content-Disposition'));

        $this->deleteJson("{$base}/{$document->uuid}", [], $this->key())->assertOk()->assertJsonPath('data.uuid', $document->uuid);
        Storage::disk('local')->assertMissing($document->file_path);
        $this->assertSoftDeleted($document);
        $this->getJson($base)->assertOk()->assertJsonPath('data', []);
    }

    public function test_an_upload_is_validated_by_the_web_panels_rules(): void
    {
        Sanctum::actingAs($this->staff('receptionist'));
        $base = "/api/v1/patients/{$this->patient->uuid}/documents";

        $this->post($base, ['type' => 'passport'], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['type', 'file']);

        $this->post($base, ['type' => 'other', 'file' => UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['file']);

        $this->post($base, ['type' => 'other', 'file' => UploadedFile::fake()->create('scan.pdf', 10241, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['file']);

        $this->assertSame(0, PatientDocument::count());
    }

    public function test_a_repeated_upload_with_the_same_key_files_one_document(): void
    {
        Sanctum::actingAs($this->staff('receptionist'));
        $base = "/api/v1/patients/{$this->patient->uuid}/documents";
        $headers = ['Accept' => 'application/json'] + $this->key();

        $first = $this->post($base, ['type' => 'id', 'file' => UploadedFile::fake()->image('id.jpg')], $headers)->assertCreated()->json('data.uuid');
        $again = $this->post($base, ['type' => 'id', 'file' => UploadedFile::fake()->image('id.jpg')], $headers)
            ->assertCreated()->assertHeader('Idempotent-Replay', 'true')->json('data.uuid');

        $this->assertSame($first, $again);
        $this->assertSame(1, PatientDocument::count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_reading_documents_needs_the_patient_and_writing_needs_patients_update(): void
    {
        app(CurrentHospital::class)->set($this->h->id);
        $document = app(DocumentService::class)->store($this->patient, UploadedFile::fake()->create('a.pdf', 5, 'application/pdf'), 'result', null);
        $base = "/api/v1/patients/{$this->patient->uuid}/documents";

        // A pharmacist sees the patient but may not change the record.
        Sanctum::actingAs($this->staff('pharmacist'));
        $this->getJson($base)->assertOk()->assertJsonPath('meta.can_manage', false)->assertJsonCount(1, 'data');
        $this->post($base, ['type' => 'other', 'file' => UploadedFile::fake()->create('b.pdf', 5, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->deleteJson("{$base}/{$document->uuid}")->assertForbidden();
        $this->assertSame(1, PatientDocument::count());
    }

    public function test_every_endpoint_needs_a_token(): void
    {
        $p = $this->patient->uuid;
        foreach (["patients/{$p}/documents", "patients/{$p}/insurances", "patients/{$p}/dependents", "patients/{$p}/treatments", "patients/{$p}/id-card", "patients/{$p}/shareable"] as $path) {
            $this->getJson("/api/v1/{$path}")->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        }
    }

    public function test_another_hospitals_patient_and_document_are_not_found(): void
    {
        $theirs = $this->otherHospitalPatient();
        app(CurrentHospital::class)->set($theirs->hospital_id);
        $theirDocument = app(DocumentService::class)->store($theirs, UploadedFile::fake()->create('t.pdf', 5, 'application/pdf'), 'result', null);

        Sanctum::actingAs($this->staff('receptionist'));
        $this->getJson("/api/v1/patients/{$theirs->uuid}/documents")->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->post("/api/v1/patients/{$theirs->uuid}/documents", ['type' => 'other', 'file' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf')], ['Accept' => 'application/json'])->assertNotFound();
        // Their document under my patient: the binding is tenant-scoped.
        $this->getJson("/api/v1/patients/{$this->patient->uuid}/documents/{$theirDocument->uuid}/download")->assertNotFound();
        $this->deleteJson("/api/v1/patients/{$this->patient->uuid}/documents/{$theirDocument->uuid}")->assertNotFound();
        $this->assertNotSoftDeleted($theirDocument);
    }

    public function test_a_document_of_another_patient_is_not_reached_through_this_one(): void
    {
        $sibling = Patient::factory()->create(['hospital_id' => $this->h->id]);
        app(CurrentHospital::class)->set($this->h->id);
        $theirs = app(DocumentService::class)->store($sibling, UploadedFile::fake()->create('s.pdf', 5, 'application/pdf'), 'result', null);

        Sanctum::actingAs($this->staff('receptionist'));
        $this->getJson("/api/v1/patients/{$this->patient->uuid}/documents/{$theirs->uuid}/download")->assertNotFound();
        $this->deleteJson("/api/v1/patients/{$this->patient->uuid}/documents/{$theirs->uuid}")->assertNotFound();
        $this->assertNotSoftDeleted($theirs);
    }

    // ── Insurance cover ──────────────────────────────────────────────────

    public function test_insurance_cover_is_listed_added_and_removed_through_the_service(): void
    {
        Sanctum::actingAs($this->staff('accountant'));
        $insurer = InsuranceProvider::factory()->create(['hospital_id' => $this->h->id, 'name' => 'Jubilee', 'is_active' => true]);
        InsuranceProvider::factory()->create(['hospital_id' => $this->h->id, 'name' => 'Closed Co', 'is_active' => false]);
        $base = "/api/v1/patients/{$this->patient->uuid}/insurances";

        $this->getJson($base)->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.can_manage', true)
            ->assertJsonPath('meta.providers', [['id' => $insurer->id, 'name' => 'Jubilee']]);

        $row = $this->postJson($base, [
            'insurance_provider_id' => $insurer->id, 'member_no' => 'JUB-001', 'coverage_percent' => '80',
            'valid_from' => now()->subMonth()->toDateString(), 'valid_to' => now()->addYear()->toDateString(),
        ], $this->key())->assertCreated()
            ->assertJsonPath('data.provider.name', 'Jubilee')
            ->assertJsonPath('data.coverage_percent', '80.00')
            ->assertJsonPath('data.coverage_label', '80%')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.valid', true)
            ->json('data');

        $this->getJson($base)->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("{$base}/{$row['id']}", [], $this->key())->assertOk();
        $this->assertSoftDeleted(PatientInsurance::withTrashed()->find($row['id']));
    }

    public function test_cover_past_its_end_date_reads_as_not_valid_and_is_active_unless_said(): void
    {
        Sanctum::actingAs($this->staff('accountant'));
        $insurer = InsuranceProvider::factory()->create(['hospital_id' => $this->h->id, 'is_active' => true]);

        $this->postJson("/api/v1/patients/{$this->patient->uuid}/insurances", [
            'insurance_provider_id' => $insurer->id, 'member_no' => 'OLD-1', 'coverage_percent' => 100,
            'valid_from' => now()->subYears(2)->toDateString(), 'valid_to' => now()->subDay()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.is_active', true)->assertJsonPath('data.valid', false)->assertJsonPath('data.coverage_label', '100%');

        $this->postJson("/api/v1/patients/{$this->patient->uuid}/insurances", [
            'insurance_provider_id' => $insurer->id, 'member_no' => 'OFF-1', 'coverage_percent' => 50, 'is_active' => false,
        ])->assertCreated()->assertJsonPath('data.is_active', false)->assertJsonPath('data.valid', false);
    }

    public function test_insurance_cover_is_validated_and_scoped_to_this_hospitals_insurers(): void
    {
        Sanctum::actingAs($this->staff('accountant'));
        $theirInsurer = InsuranceProvider::factory()->create(['hospital_id' => Hospital::factory()->create()->id]);
        $base = "/api/v1/patients/{$this->patient->uuid}/insurances";

        $this->postJson($base, ['coverage_percent' => 140, 'valid_from' => '2026-05-01', 'valid_to' => '2026-01-01'])
            ->assertStatus(422)->assertJsonValidationErrors(['insurance_provider_id', 'member_no', 'coverage_percent', 'valid_to']);
        $this->postJson($base, ['insurance_provider_id' => $theirInsurer->id, 'member_no' => 'X', 'coverage_percent' => 50])
            ->assertStatus(422)->assertJsonValidationErrors(['insurance_provider_id']);
        $this->assertSame(0, PatientInsurance::count());
    }

    public function test_insurance_cover_needs_insurance_view_to_see_and_insurance_manage_to_change(): void
    {
        $insurer = InsuranceProvider::factory()->create(['hospital_id' => $this->h->id]);
        app(CurrentHospital::class)->set($this->h->id);
        $cover = app(PatientService::class)->addInsurance($this->patient, ['insurance_provider_id' => $insurer->id, 'member_no' => 'M1', 'coverage_percent' => '100', 'is_active' => true]);
        $base = "/api/v1/patients/{$this->patient->uuid}/insurances";

        // A receptionist sees cover (insurance.view) but may not change it.
        Sanctum::actingAs($this->staff('receptionist'));
        $this->getJson($base)->assertOk()->assertJsonPath('meta.can_manage', false)->assertJsonPath('meta.providers', []);
        $this->postJson($base, ['insurance_provider_id' => $insurer->id, 'member_no' => 'M2', 'coverage_percent' => 50])->assertForbidden();
        $this->deleteJson("{$base}/{$cover->id}")->assertForbidden();

        // A nurse has no insurance at all.
        Sanctum::actingAs($this->staff('nurse'));
        $this->getJson($base)->assertForbidden();

        // Another hospital's patient, and another patient's cover row.
        Sanctum::actingAs($this->staff('accountant'));
        $this->getJson('/api/v1/patients/'.$this->otherHospitalPatient()->uuid.'/insurances')->assertNotFound();
        $sibling = Patient::factory()->create(['hospital_id' => $this->h->id]);
        $this->deleteJson("/api/v1/patients/{$sibling->uuid}/insurances/{$cover->id}")->assertNotFound();
        $this->assertNotSoftDeleted($cover);
    }

    // ── Dependents ───────────────────────────────────────────────────────

    public function test_dependents_are_linked_listed_both_ways_and_unlinked(): void
    {
        Sanctum::actingAs($this->staff('receptionist'));
        $child = Patient::factory()->create(['hospital_id' => $this->h->id, 'first_name' => 'Baby', 'last_name' => 'Nakato']);
        $base = "/api/v1/patients/{$this->patient->uuid}/dependents";

        $this->getJson($base)->assertOk()
            ->assertJsonPath('data.dependents', [])
            ->assertJsonPath('data.can_manage', true)
            ->assertJsonPath('data.relationships.0', ['value' => 'child', 'label' => 'Child']);

        $link = $this->postJson($base, ['dependent_uuid' => $child->uuid, 'relationship' => 'child'], $this->key())
            ->assertCreated()
            ->assertJsonPath('message', 'Baby Nakato linked as child.')
            ->assertJsonPath('data.patient.uuid', $child->uuid)
            ->assertJsonPath('data.relationship_label', 'Child')
            ->json('data');

        $this->getJson($base)->assertOk()->assertJsonCount(1, 'data.dependents');
        // From the child's side: the guardian.
        $this->getJson("/api/v1/patients/{$child->uuid}/dependents")->assertOk()
            ->assertJsonPath('data.guardians.0.patient.uuid', $this->patient->uuid);

        $this->deleteJson("{$base}/{$link['id']}", [], $this->key())->assertOk();
        $this->assertSame(0, PatientDependent::count());
    }

    public function test_a_self_link_a_duplicate_and_a_bad_uuid_are_refused_with_the_field(): void
    {
        Sanctum::actingAs($this->staff('receptionist'));
        $child = Patient::factory()->create(['hospital_id' => $this->h->id, 'first_name' => 'Baby', 'last_name' => 'Okello']);
        $base = "/api/v1/patients/{$this->patient->uuid}/dependents";

        $this->postJson($base, ['dependent_uuid' => $this->patient->uuid, 'relationship' => 'child'])
            ->assertStatus(422)->assertJsonPath('errors.dependent_uuid.0', 'A patient cannot be their own dependent.');

        $this->postJson($base, ['dependent_uuid' => $child->uuid, 'relationship' => 'child'])->assertCreated();
        $this->postJson($base, ['dependent_uuid' => $child->uuid, 'relationship' => 'ward'])
            ->assertStatus(422)->assertJsonPath('errors.dependent_uuid.0', 'Baby Okello is already linked.');

        $this->postJson($base, ['dependent_uuid' => 'not-a-uuid', 'relationship' => 'cousin'])
            ->assertStatus(422)->assertJsonValidationErrors(['dependent_uuid', 'relationship']);

        // Another hospital's patient does not validate (and does not leak that it exists).
        $this->postJson($base, ['dependent_uuid' => $this->otherHospitalPatient()->uuid, 'relationship' => 'child'])
            ->assertStatus(422)->assertJsonValidationErrors(['dependent_uuid']);
        $this->assertSame(1, PatientDependent::count());
    }

    public function test_an_archived_patient_cannot_be_linked_and_says_so_by_the_field(): void
    {
        Sanctum::actingAs($this->staff('receptionist'));
        $gone = Patient::factory()->create(['hospital_id' => $this->h->id]);
        $gone->delete();

        $this->postJson("/api/v1/patients/{$this->patient->uuid}/dependents", ['dependent_uuid' => $gone->uuid, 'relationship' => 'child'])
            ->assertStatus(422)->assertJsonValidationErrors(['dependent_uuid']);
        $this->assertSame(0, PatientDependent::count());
    }

    public function test_a_file_is_kept_as_what_its_bytes_are_not_what_its_name_claims(): void
    {
        Sanctum::actingAs($this->staff('receptionist'));
        $path = tempnam(sys_get_temp_dir(), 'png');
        imagepng(imagecreatetruecolor(4, 4), $path);
        $upload = new UploadedFile($path, 'scan.pdf', 'application/pdf', null, true);

        $this->post("/api/v1/patients/{$this->patient->uuid}/documents", ['type' => 'other', 'file' => $upload], ['Accept' => 'application/json'])->assertCreated();

        $document = PatientDocument::query()->sole();
        $this->assertStringEndsWith('.png', $document->file_path);
        $this->assertSame('image/png', $document->mime);
        $this->assertSame('scan.pdf', $document->original_name, 'the name the person gave is still shown');
    }

    public function test_a_tiny_file_is_never_zero_kb(): void
    {
        $this->assertSame('1 KB', \App\Http\Controllers\Api\V1\PatientDocumentController::sizeLabel(300));
        $this->assertSame('120 KB', \App\Http\Controllers\Api\V1\PatientDocumentController::sizeLabel(122880));
        $this->assertSame('1.5 MB', \App\Http\Controllers\Api\V1\PatientDocumentController::sizeLabel(1572864));
    }

    public function test_linking_needs_patients_update_and_another_hospitals_records_are_not_found(): void
    {
        $child = Patient::factory()->create(['hospital_id' => $this->h->id]);
        app(CurrentHospital::class)->set($this->h->id);
        $link = app(PatientService::class)->linkDependent($this->patient, $child->uuid, 'child');
        $base = "/api/v1/patients/{$this->patient->uuid}/dependents";

        Sanctum::actingAs($this->staff('pharmacist'));
        $this->getJson($base)->assertOk()->assertJsonPath('data.can_manage', false);
        $this->postJson($base, ['dependent_uuid' => $child->uuid, 'relationship' => 'child'])->assertForbidden();
        $this->deleteJson("{$base}/{$link->id}")->assertForbidden();

        Sanctum::actingAs($this->staff('receptionist'));
        $this->getJson('/api/v1/patients/'.$this->otherHospitalPatient()->uuid.'/dependents')->assertNotFound();
        // The link belongs to its guardian: not removable through the child.
        $this->deleteJson("/api/v1/patients/{$child->uuid}/dependents/{$link->id}")->assertNotFound();
        $this->assertSame(1, PatientDependent::count());
    }

    // ── Treatment records ────────────────────────────────────────────────

    public function test_a_treatment_record_is_added_with_photos_listed_and_its_photo_streams(): void
    {
        Sanctum::actingAs($this->staff('nurse'));
        $base = "/api/v1/patients/{$this->patient->uuid}/treatments";

        $this->getJson($base)->assertOk()->assertJsonPath('data', [])
            ->assertJsonPath('meta.can_create', true)->assertJsonPath('meta.max_photos', 12)->assertJsonPath('meta.photo_max_kb', 8192);

        $made = $this->post($base, [
            'procedure' => 'Wound dressing',
            'description' => 'Left forearm, clean.',
            'photos' => [UploadedFile::fake()->image('before.jpg'), UploadedFile::fake()->image('after.jpg')],
        ], ['Accept' => 'application/json'] + $this->key())->assertCreated()
            ->assertJsonPath('data.procedure', 'Wound dressing')
            ->assertJsonPath('data.can_delete', true)
            ->assertJsonCount(2, 'data.photos')
            ->json('data');

        $record = TreatmentRecord::where('uuid', $made['uuid'])->sole();
        $this->assertNotNull($record->visit_id, 'on the patient\'s current visit, as the web panel files it');
        $this->getJson($base)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.photos.1.path', $made['photos'][1]['path']);

        $photo = $this->get('/api/v1/'.$made['photos'][0]['path'])->assertOk();
        $this->assertStringStartsWith('image/', (string) $photo->headers->get('Content-Type'));

        $this->deleteJson("{$base}/{$record->uuid}", [], $this->key())->assertOk();
        $this->assertSoftDeleted($record);
        $this->assertSame([], Storage::disk('local')->allFiles());
        // A photo of the removed record is gone, not an error.
        $this->get('/api/v1/'.$made['photos'][0]['path'])->assertNotFound();
    }

    public function test_treatment_records_are_validated_and_replayed_once(): void
    {
        Sanctum::actingAs($this->staff('nurse'));
        $base = "/api/v1/patients/{$this->patient->uuid}/treatments";

        $this->post($base, ['procedure' => '', 'performed_at' => 'yesterday-ish', 'photos' => [UploadedFile::fake()->create('notes.pdf', 5, 'application/pdf')]], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['procedure', 'performed_at', 'photos.0']);

        $headers = ['Accept' => 'application/json'] + $this->key();
        $body = ['procedure' => 'Suture removal', 'performed_at' => now()->subHour()->format('Y-m-d H:i')];
        $first = $this->post($base, $body, $headers)->assertCreated()->json('data.uuid');
        $again = $this->post($base, $body, $headers)->assertCreated()->json('data.uuid');
        $this->assertSame($first, $again);
        $this->assertSame(1, TreatmentRecord::count());
    }

    public function test_treatment_records_follow_the_treatments_permissions_and_tenancy(): void
    {
        app(CurrentHospital::class)->set($this->h->id);
        $nurse = $this->staff('nurse');
        $record = app(TreatmentService::class)->create($this->patient, ['procedure' => 'Dressing'], [], $nurse->id);
        $base = "/api/v1/patients/{$this->patient->uuid}/treatments";

        // A receptionist has no treatments permission at all.
        Sanctum::actingAs($this->staff('receptionist'));
        $this->getJson($base)->assertForbidden();
        $this->post($base, ['procedure' => 'X'], ['Accept' => 'application/json'])->assertForbidden();
        $this->deleteJson("{$base}/{$record->uuid}")->assertForbidden();

        Sanctum::actingAs($nurse);
        $this->getJson('/api/v1/patients/'.$this->otherHospitalPatient()->uuid.'/treatments')->assertNotFound();
        $sibling = Patient::factory()->create(['hospital_id' => $this->h->id]);
        $this->deleteJson("/api/v1/patients/{$sibling->uuid}/treatments/{$record->uuid}")->assertNotFound();
        $this->assertNotSoftDeleted($record);
    }

    // ── The ID card ──────────────────────────────────────────────────────

    public function test_the_id_card_is_the_web_pdf_for_anyone_who_may_see_the_patient(): void
    {
        Sanctum::actingAs($this->staff('pharmacist'));

        $res = $this->get("/api/v1/patients/{$this->patient->uuid}/id-card")->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $res->getContent());

        $this->get('/api/v1/patients/'.$this->otherHospitalPatient()->uuid.'/id-card', ['Accept' => 'application/json'])->assertNotFound();
    }
}
