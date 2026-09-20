<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Patients\Panels\Treatments;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\TreatmentRecord;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The treatment-records panel (ported from the retired TreatmentRecordTest
 * store/destroy paths, which drove TreatmentRecordController over HTTP). The
 * record page and the photo stream stay classic GETs and keep their own test.
 */
class PatientTreatmentsPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        Storage::fake('local');
    }

    private function acting(Hospital $h, string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    private function panel(Patient $patient)
    {
        Livewire::withoutLazyLoading();

        return Livewire::test(Treatments::class, ['patientId' => $patient->id]);
    }

    public function test_doctor_adds_a_record_with_photos(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'doctor');
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        $this->panel($patient)
            ->call('openAdd')
            ->set('procedure', 'Wound suturing')
            ->set('description', '3 sutures, left forearm')
            ->set('photos', [UploadedFile::fake()->image('wound1.jpg'), UploadedFile::fake()->image('wound2.jpg')])
            ->call('add')
            ->assertHasNoErrors()
            ->assertSet('showAdd', false);

        $record = TreatmentRecord::firstOrFail();
        $this->assertSame('Wound suturing', $record->procedure);
        $this->assertSame(2, $record->photos()->count());
        Storage::disk('local')->assertExists($record->photos()->first()->photo_path);
        $this->assertStringStartsWith("treatments/{$h->id}/{$record->id}/", $record->photos()->first()->photo_path);
    }

    public function test_delete_removes_photos_from_disk(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'doctor');
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        $this->panel($patient)
            ->call('openAdd')
            ->set('procedure', 'X')
            ->set('photos', [UploadedFile::fake()->image('a.jpg')])
            ->call('add')
            ->assertHasNoErrors();

        $record = TreatmentRecord::firstOrFail();
        $path = $record->photos()->first()->photo_path;

        $this->panel($patient)->call('delete', $record->id)->assertHasNoErrors();

        Storage::disk('local')->assertMissing($path);
        $this->assertSoftDeleted('treatment_records', ['id' => $record->id]);
    }

    public function test_a_photo_can_be_dropped_before_saving(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'doctor');
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        $this->panel($patient)
            ->call('openAdd')
            ->set('procedure', 'Dressing')
            ->set('photos', [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')])
            ->call('removePhoto', 0)
            ->call('add')
            ->assertHasNoErrors();

        $this->assertSame(1, TreatmentRecord::firstOrFail()->photos()->count());
    }

    public function test_validation_requires_a_procedure(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'doctor');
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        $this->panel($patient)->call('openAdd')->call('add')->assertHasErrors('procedure');
        $this->assertDatabaseCount('treatment_records', 0);
    }

    public function test_receptionist_cannot_open_the_treatment_panel(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'receptionist'); // no treatments.view
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        $this->panel($patient)->assertForbidden();
        $this->assertDatabaseCount('treatment_records', 0);
    }

    public function test_a_patient_of_another_hospital_cannot_be_reached(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $patientB = Patient::factory()->create(['hospital_id' => $b->id]);

        $this->acting($a, 'doctor');
        $this->expectException(ModelNotFoundException::class);
        $this->panel($patientB);
    }
}
