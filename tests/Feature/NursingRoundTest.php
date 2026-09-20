<?php

namespace Tests\Feature;

use App\Enums\AdmissionStatus;
use App\Livewire\Admissions\Panels\Medications;
use App\Livewire\Admissions\Panels\NursingNotes;
use App\Livewire\Admissions\Panels\VitalRounds;
use App\Models\Admission;
use App\Models\Bed;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Support\CurrentHospital;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Nursing rounds are three #[Lazy] panels on the admission workspace since
 * Phase 3 (App\Livewire\Admissions\Panels\*); NursingController is gone. The
 * assertions are the ones the controller carried: entries appended to the
 * append-only logs, the "active admission only" guard as the same 422, RBAC
 * and tenant isolation.
 */
class NursingRoundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function user(Hospital $h, string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    /** A reader: ipd.view but not ipd.manage (no seeded role has that shape). */
    private function readOnlyStaff(Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'nurse']);
        $u->syncRoles([]);
        $u->givePermissionTo('ipd.view');

        return $u;
    }

    private function admission(Hospital $h): Admission
    {
        app(CurrentHospital::class)->set($h->id);
        $ward = Ward::factory()->create(['hospital_id' => $h->id]);
        $bed = Bed::factory()->create(['hospital_id' => $h->id, 'ward_id' => $ward->id]);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        return app(AdmissionService::class)->admit($patient, $bed, []);
    }

    /** @param class-string $panel */
    private function panel(string $panel, User $user, int $admissionId): Testable
    {
        Livewire::withoutLazyLoading();

        return Livewire::actingAs($user)->test($panel, ['admissionId' => $admissionId]);
    }

    public function test_nurse_records_note_vitals_and_medication(): void
    {
        $h = Hospital::factory()->create();
        $nurse = $this->user($h, 'nurse');
        $adm = $this->admission($h);

        $this->panel(NursingNotes::class, $nurse, $adm->id)
            ->set('note', 'Resting comfortably')
            ->call('add')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success')
            ->assertSet('note', null);

        $this->panel(VitalRounds::class, $nurse, $adm->id)
            ->set('temperature', '37.1')
            ->set('blood_pressure', '118/76')
            ->call('add')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success');

        $this->panel(Medications::class, $nurse, $adm->id)
            ->set('drug_name', 'Paracetamol')
            ->set('dose', '1g')
            ->set('route', 'IV')
            ->set('status', 'given')
            ->call('add')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success');

        $this->assertSame(1, $adm->nursingNotes()->count());
        $this->assertSame(1, $adm->vitalRounds()->count());
        $this->assertSame(1, $adm->medications()->count());

        $this->assertSame('Resting comfortably', $adm->nursingNotes()->first()?->note);
        $this->assertSame('118/76', $adm->vitalRounds()->first()?->blood_pressure);
        $this->assertSame('Paracetamol', $adm->medications()->first()?->drug_name);
        $this->assertSame($nurse->id, $adm->nursingNotes()->first()?->recorded_by);
    }

    /** Append-only logs are refused once the stay is closed — same 422 as before. */
    public function test_closed_admission_rejects_new_entries(): void
    {
        $h = Hospital::factory()->create();
        $nurse = $this->user($h, 'nurse');
        $adm = $this->admission($h);
        app(AdmissionService::class)->discharge($adm->fresh(), AdmissionStatus::Discharged);

        $this->panel(NursingNotes::class, $nurse, $adm->id)
            ->set('note', 'late')
            ->call('add')
            ->assertStatus(422);
        $this->assertSame(0, $adm->nursingNotes()->count());

        $this->panel(VitalRounds::class, $nurse, $adm->id)
            ->set('temperature', '37.0')
            ->call('add')
            ->assertStatus(422);
        $this->assertSame(0, $adm->vitalRounds()->count());

        $this->panel(Medications::class, $nurse, $adm->id)
            ->set('drug_name', 'Paracetamol')
            ->set('status', 'given')
            ->call('add')
            ->assertStatus(422);
        $this->assertSame(0, $adm->medications()->count());
    }

    /** Validation still lives in the FormRequest rules the panels delegate to. */
    public function test_invalid_entries_are_rejected(): void
    {
        $h = Hospital::factory()->create();
        $nurse = $this->user($h, 'nurse');
        $adm = $this->admission($h);

        $this->panel(NursingNotes::class, $nurse, $adm->id)
            ->set('note', '')
            ->call('add')
            ->assertHasErrors('note');

        $this->panel(VitalRounds::class, $nurse, $adm->id)
            ->set('blood_pressure', 'not-a-bp')
            ->call('add')
            ->assertHasErrors('blood_pressure');

        $this->panel(Medications::class, $nurse, $adm->id)
            ->set('drug_name', '')
            ->set('status', 'given')
            ->call('add')
            ->assertHasErrors('drug_name');

        $this->assertSame(0, $adm->nursingNotes()->count());
        $this->assertSame(0, $adm->vitalRounds()->count());
        $this->assertSame(0, $adm->medications()->count());
    }

    public function test_receptionist_cannot_record_nursing_entries(): void
    {
        $h = Hospital::factory()->create();
        $adm = $this->admission($h);
        $recep = $this->user($h, 'receptionist');

        // No ipd.view at all: the panel refuses to mount, as the workspace does.
        $this->panel(NursingNotes::class, $recep, $adm->id)->assertForbidden();
        $this->assertSame(0, $adm->nursingNotes()->count());
    }

    /** ipd.view without ipd.manage reads the chart but cannot append to it. */
    public function test_a_read_only_role_can_read_but_not_append(): void
    {
        $h = Hospital::factory()->create();
        $adm = $this->admission($h);
        $reader = $this->readOnlyStaff($h);

        $this->panel(NursingNotes::class, $reader, $adm->id)
            ->assertOk()
            ->set('note', 'from a reader')
            ->call('add')
            ->assertForbidden();

        $this->assertSame(0, $adm->nursingNotes()->count());
    }

    public function test_nursing_entries_are_tenant_isolated(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $admB = $this->admission($b);
        $nurseA = $this->user($a, 'nurse');

        app(CurrentHospital::class)->set($a->id);

        $this->expectException(ModelNotFoundException::class);
        $this->panel(NursingNotes::class, $nurseA, $admB->id);
    }
}
