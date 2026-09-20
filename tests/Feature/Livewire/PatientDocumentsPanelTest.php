<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Patients\Panels\Documents;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The documents panel (ported from the retired PatientDocumentTest, which drove
 * PatientDocumentController@store/destroy over HTTP). The download is still a
 * classic streamed GET, so those assertions stay HTTP.
 */
class PatientDocumentsPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        Storage::fake('local');
    }

    private function user(Hospital $h, string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function acting(Hospital $h, string $role): User
    {
        $u = $this->user($h, $role);
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    private function panel(Patient $patient)
    {
        Livewire::withoutLazyLoading();

        return Livewire::test(Documents::class, ['patientId' => $patient->id]);
    }

    public function test_upload_download_and_delete(): void
    {
        $h = Hospital::factory()->create();
        $user = $this->acting($h, 'hospital_admin');
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        $this->panel($patient)
            ->call('openUpload')
            ->set('type', 'insurance')
            ->set('note', 'Scheme card')
            ->set('file', UploadedFile::fake()->create('cover.pdf', 40, 'application/pdf'))
            ->call('upload')
            ->assertHasNoErrors()
            ->assertSet('showUpload', false);

        $doc = PatientDocument::where('patient_id', $patient->id)->firstOrFail();
        $this->assertSame('insurance', $doc->type);
        $this->assertSame('Scheme card', $doc->note);
        Storage::disk('local')->assertExists($doc->file_path);
        $this->assertStringStartsWith("patients/{$h->id}/{$patient->id}/", $doc->file_path);

        $this->actingAs($user)->get("/admin/patients/{$patient->uuid}/documents/{$doc->uuid}")->assertOk();

        $this->panel($patient)->call('delete', $doc->id)->assertHasNoErrors();
        Storage::disk('local')->assertMissing($doc->file_path);
        $this->assertSoftDeleted('patient_documents', ['id' => $doc->id]);
    }

    public function test_upload_requires_a_file_and_a_known_type(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'hospital_admin');
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        $this->panel($patient)
            ->call('openUpload')
            ->set('type', 'not-a-type')
            ->call('upload')
            ->assertHasErrors(['type', 'file']);

        $this->assertDatabaseCount('patient_documents', 0);
    }

    public function test_a_role_without_patient_update_cannot_upload_or_delete(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'hospital_admin');
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $this->panel($patient)
            ->call('openUpload')
            ->set('type', 'id')
            ->set('file', UploadedFile::fake()->create('id.pdf', 10, 'application/pdf'))
            ->call('upload');
        $doc = PatientDocument::where('patient_id', $patient->id)->firstOrFail();

        // Pharmacist: patients.view only — may read the panel, never write it.
        $this->acting($h, 'pharmacist');
        $this->panel($patient)->assertOk()->call('openUpload')->assertForbidden();
        $this->panel($patient)->call('delete', $doc->id)->assertForbidden();

        $this->assertDatabaseCount('patient_documents', 1);
    }

    public function test_documents_are_tenant_isolated(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $patientB = Patient::factory()->create(['hospital_id' => $b->id]);

        $this->acting($b, 'hospital_admin');
        $this->panel($patientB)
            ->call('openUpload')
            ->set('type', 'id')
            ->set('file', UploadedFile::fake()->create('id.pdf', 10, 'application/pdf'))
            ->call('upload');
        $doc = PatientDocument::withoutGlobalScopes()->where('patient_id', $patientB->id)->firstOrFail();

        // A cannot download B's document (patient not resolvable in A's scope).
        $adminA = $this->user($a, 'hospital_admin');
        $this->actingAs($adminA)
            ->get("/admin/patients/{$patientB->uuid}/documents/{$doc->uuid}")
            ->assertNotFound();

        // …nor mount the panel for B's patient.
        $this->acting($a, 'hospital_admin');
        $this->expectException(ModelNotFoundException::class);
        $this->panel($patientB);
    }
}
