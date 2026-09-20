<?php

namespace Tests\Feature;

use App\Livewire\Patients\TreatmentShow;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\TreatmentRecord;
use App\Models\User;
use App\Services\TreatmentService;
use App\Support\CurrentHospital;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The treatment record page (App\Livewire\Patients\TreatmentShow) and the one
 * path that stayed classic after the Phase 3 conversion: the private photo
 * stream (TreatmentRecordController@photo). Creating records is covered by
 * Tests\Feature\Livewire\PatientTreatmentsPanelTest.
 */
class TreatmentRecordTest extends TestCase
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

    private function record(Hospital $h, Patient $patient, string $procedure = 'Wound suturing'): TreatmentRecord
    {
        app(CurrentHospital::class)->set($h->id);

        return app(TreatmentService::class)->create(
            $patient,
            ['procedure' => $procedure, 'description' => 'Cleaned and closed.', 'meta' => ['sutures' => 4, 'anaesthetic' => 'lidocaine']],
            [UploadedFile::fake()->image('wound.jpg')],
        );
    }

    public function test_the_record_page_and_photo_stream_render(): void
    {
        $h = Hospital::factory()->create();
        $doctor = $this->user($h, 'doctor');
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $record = $this->record($h, $patient);

        $this->actingAs($doctor)
            ->get("/admin/patients/{$patient->uuid}/treatments/{$record->uuid}")
            ->assertOk()
            ->assertSee('Wound suturing');

        Livewire::actingAs($doctor)->test(TreatmentShow::class, ['patient' => $patient->uuid, 'treatment' => $record->uuid])
            ->assertOk()
            ->assertSet('recordId', $record->id)
            ->assertSet('patientId', $patient->id)
            ->assertSee('Wound suturing')
            ->assertSee('Cleaned and closed.')
            ->assertSee('Sutures')          // the meta JSON as a definition list
            ->assertSee('lidocaine')
            ->assertSee($patient->full_name);

        $photo = $record->photos()->firstOrFail();
        $this->actingAs($doctor)
            ->get("/admin/patients/{$patient->uuid}/treatment-photos/{$photo->id}")
            ->assertOk();
    }

    public function test_a_record_of_another_patient_is_not_found(): void
    {
        $h = Hospital::factory()->create();
        $doctor = $this->user($h, 'doctor');
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $other = Patient::factory()->create(['hospital_id' => $h->id]);
        $record = $this->record($h, $patient);

        $this->actingAs($doctor)
            ->get("/admin/patients/{$other->uuid}/treatments/{$record->uuid}")
            ->assertNotFound();
    }

    public function test_treatment_records_are_tenant_isolated(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $patientB = Patient::factory()->create(['hospital_id' => $b->id]);
        $recordB = $this->record($b, $patientB, 'Secret op');

        app(CurrentHospital::class)->set($a->id);
        $this->actingAs($this->user($a, 'doctor'))
            ->get("/admin/patients/{$patientB->uuid}/treatments/{$recordB->uuid}")
            ->assertNotFound();
    }

    public function test_another_tenants_record_cannot_be_mounted(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $patientB = Patient::factory()->create(['hospital_id' => $b->id]);
        $recordB = $this->record($b, $patientB, 'Secret op');

        app(CurrentHospital::class)->set($a->id);
        $this->actingAs($this->user($a, 'doctor'));

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(TreatmentShow::class, ['patient' => $patientB->uuid, 'treatment' => $recordB->uuid]);
    }

    public function test_the_photo_stream_refuses_a_reader_without_treatment_rights(): void
    {
        $h = Hospital::factory()->create();
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $record = $this->record($h, $patient);
        $photo = $record->photos()->firstOrFail();

        $stripped = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor']);
        $stripped->syncRoles([]);
        $stripped->givePermissionTo(['access-admin']);

        $this->actingAs($stripped)
            ->get("/admin/patients/{$patient->uuid}/treatment-photos/{$photo->id}")
            ->assertForbidden();
    }

    public function test_the_record_page_is_forbidden_without_treatment_view(): void
    {
        $h = Hospital::factory()->create();
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $record = $this->record($h, $patient);

        $stripped = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor']);
        $stripped->syncRoles([]);
        $stripped->givePermissionTo(['access-admin']);

        Livewire::actingAs($stripped)->test(TreatmentShow::class, ['patient' => $patient->uuid, 'treatment' => $record->uuid])
            ->assertForbidden();
    }

    public function test_deleting_a_record_removes_its_photos_and_returns_to_the_patient(): void
    {
        $h = Hospital::factory()->create();
        $doctor = $this->user($h, 'doctor');
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $record = $this->record($h, $patient);
        $path = $record->photos()->firstOrFail()->photo_path;

        Storage::disk('local')->assertExists($path);

        Livewire::actingAs($doctor)->test(TreatmentShow::class, ['patient' => $patient->uuid, 'treatment' => $record->uuid])
            ->call('delete')
            ->assertRedirect(route('admin.patients.show', $patient));

        $this->assertNull(TreatmentRecord::find($record->id));
        Storage::disk('local')->assertMissing($path);
        $this->assertSame('Treatment record removed.', session('success'));
    }

    public function test_a_reader_without_treatment_manage_cannot_delete_a_record(): void
    {
        $h = Hospital::factory()->create();
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $record = $this->record($h, $patient);

        $reader = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor']);
        $reader->syncRoles([]);
        $reader->givePermissionTo(['access-admin', 'treatments.view']);

        Livewire::actingAs($reader)->test(TreatmentShow::class, ['patient' => $patient->uuid, 'treatment' => $record->uuid])
            ->assertOk()
            ->call('delete')
            ->assertForbidden();

        $this->assertNotNull(TreatmentRecord::find($record->id));
    }
}
