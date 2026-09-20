<?php

namespace Tests\Feature;

use App\Enums\BedStatus;
use App\Livewire\Admissions\Index as AdmissionsIndex;
use App\Livewire\Admissions\Show as AdmissionShow;
use App\Models\Admission;
use App\Models\Bed;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Support\CurrentHospital;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdmissionHttpTest extends TestCase
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

    /** Drive the admit slide-over (the classic create/store page is gone). */
    private function admit(Hospital $h, User $user, Patient $patient, Bed $bed, ?string $reason = null): \Livewire\Features\SupportTesting\Testable
    {
        $this->actingAs($user);
        app(CurrentHospital::class)->set($h->id);

        // The dialog is VISIT-first now: a stay is an order on a visit, so
        // that is what it asks for, and the patient is whoever the visit
        // belongs to. `currentOrOpenFor` is the same call the service used to
        // make when the field was left blank.
        $visit = app(\App\Services\VisitService::class)->currentOrOpenFor($patient, $user->id);

        return Livewire::test(AdmissionsIndex::class)
            ->call('create')
            ->set('visit_id', $visit->id)
            ->set('bed_id', $bed->id)
            ->set('reason', $reason)
            ->call('save');
    }

    private function bed(Hospital $h): Bed
    {
        app(CurrentHospital::class)->set($h->id);
        $ward = Ward::factory()->create(['hospital_id' => $h->id]);

        return Bed::factory()->create(['hospital_id' => $h->id, 'ward_id' => $ward->id]);
    }

    public function test_nurse_admits_transfers_and_discharges(): void
    {
        $h = Hospital::factory()->create();
        $nurse = $this->user($h, 'nurse');
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $bedA = $this->bed($h);
        $bedB = $this->bed($h);

        $this->admit($h, $nurse, $patient, $bedA, 'Obs')
            // The dialog closes onto the list rather than navigating away.
            ->assertHasNoErrors()
            ->assertNoRedirect()
            ->assertSet('showForm', false);
        $admission = Admission::firstOrFail();
        $this->assertSame(BedStatus::Occupied, $bedA->fresh()->status);

        // Transfer + discharge are slide-overs on the Livewire workspace (Phase 3).
        Livewire::actingAs($nurse)->test(AdmissionShow::class, ['admission' => $admission->uuid])
            ->call('openTransfer')
            ->set('to_bed_id', $bedB->id)
            ->call('transfer')
            ->assertHasNoErrors()
            ->assertSet('showTransfer', false);
        $this->assertSame(BedStatus::Available, $bedA->fresh()->status);
        $this->assertSame(BedStatus::Occupied, $bedB->fresh()->status);

        Livewire::actingAs($nurse)->test(AdmissionShow::class, ['admission' => $admission->uuid])
            ->call('openDischarge')
            ->set('outcome', 'discharged')
            ->call('discharge')
            ->assertHasNoErrors()
            ->assertSet('showDischarge', false);
        $this->assertSame('discharged', $admission->fresh()->status->value);
        $this->assertSame(BedStatus::Available, $bedB->fresh()->status);
    }

    /** Bed exclusivity holds through the slide-over: an occupied bed is refused. */
    public function test_cannot_transfer_into_an_occupied_bed(): void
    {
        $h = Hospital::factory()->create();
        $nurse = $this->user($h, 'nurse');
        $bedA = $this->bed($h);
        $bedB = $this->bed($h);
        $p1 = Patient::factory()->create(['hospital_id' => $h->id]);
        $p2 = Patient::factory()->create(['hospital_id' => $h->id]);

        $this->admit($h, $nurse, $p1, $bedA)->assertHasNoErrors();
        $admissionA = Admission::firstOrFail();
        $this->admit($h, $nurse, $p2, $bedB)->assertHasNoErrors();

        Livewire::actingAs($nurse)->test(AdmissionShow::class, ['admission' => $admissionA->uuid])
            ->call('openTransfer')
            ->set('to_bed_id', $bedB->id)
            ->call('transfer')
            ->assertHasErrors('to_bed_id');

        $this->assertSame($bedA->id, $admissionA->fresh()->bed_id);
        $this->assertSame(BedStatus::Occupied, $bedA->fresh()->status);
    }

    /** Discharge bills the stay: nights × the bed's nightly rate, on the visit. */
    public function test_discharge_bills_the_bed_charge_to_the_linked_visit(): void
    {
        $h = Hospital::factory()->create(['currency' => 'UGX']);
        $nurse = $this->user($h, 'nurse');
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        app(CurrentHospital::class)->set($h->id);
        $ward = Ward::factory()->create(['hospital_id' => $h->id]);
        $bed = Bed::factory()->create(['hospital_id' => $h->id, 'ward_id' => $ward->id, 'daily_charge' => '40.00']);
        $visit = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $patient->id]);

        $admission = app(AdmissionService::class)->admit($patient, $bed, ['visit_id' => $visit->id]);

        Livewire::actingAs($nurse)->test(AdmissionShow::class, ['admission' => $admission->uuid])
            ->call('openDischarge')
            ->set('outcome', 'discharged')
            ->set('discharge_notes', 'Stable on discharge')
            ->call('discharge')
            ->assertHasNoErrors();

        $admission->refresh();
        $this->assertSame('40.00', (string) $admission->bed_charge_total);
        $this->assertSame('Stable on discharge', $admission->discharge_notes);
        $line = $visit->orderItems()->where('name', 'like', 'Bed charge%')->first();
        $this->assertNotNull($line);
        $this->assertSame('40.00', (string) $line->line_total);
    }

    /** A closed admission cannot be discharged twice or transferred. */
    public function test_a_closed_admission_refuses_transfer_and_discharge(): void
    {
        $h = Hospital::factory()->create();
        $nurse = $this->user($h, 'nurse');
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $bedA = $this->bed($h);
        $bedB = $this->bed($h);
        $admission = app(AdmissionService::class)->admit($patient, $bedA, []);
        app(AdmissionService::class)->discharge($admission->fresh(), \App\Enums\AdmissionStatus::Discharged);

        Livewire::actingAs($nurse)->test(AdmissionShow::class, ['admission' => $admission->uuid])
            ->call('openTransfer')
            ->set('to_bed_id', $bedB->id)
            ->call('transfer')
            ->assertHasErrors('to_bed_id');

        Livewire::actingAs($nurse)->test(AdmissionShow::class, ['admission' => $admission->uuid])
            ->call('openDischarge')
            ->call('discharge')
            ->assertHasErrors('outcome');

        $this->assertSame(BedStatus::Available, $bedB->fresh()->status);
    }

    /** ipd.view without ipd.manage reads the workspace but cannot act on it. */
    public function test_a_read_only_role_cannot_transfer_or_discharge(): void
    {
        $h = Hospital::factory()->create();
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $admission = app(AdmissionService::class)->admit($patient, $this->bed($h), []);

        // No seeded role has ipd.view without ipd.manage, so build one.
        $reader = User::factory()->create(['hospital_id' => $h->id, 'role' => 'nurse']);
        $reader->syncRoles([]);
        $reader->givePermissionTo('ipd.view');

        Livewire::actingAs($reader)->test(AdmissionShow::class, ['admission' => $admission->uuid])
            ->assertOk()
            ->call('openDischarge')
            ->assertForbidden();

        Livewire::actingAs($reader)->test(AdmissionShow::class, ['admission' => $admission->uuid])
            ->call('openTransfer')
            ->assertForbidden();

        $this->assertTrue($admission->fresh()->status->isActive());
    }

    public function test_cannot_admit_to_an_occupied_bed_via_http(): void
    {
        $h = Hospital::factory()->create();
        $nurse = $this->user($h, 'nurse');
        $bed = $this->bed($h);
        $p1 = Patient::factory()->create(['hospital_id' => $h->id]);
        $p2 = Patient::factory()->create(['hospital_id' => $h->id]);

        $this->admit($h, $nurse, $p1, $bed)->assertHasNoErrors();
        // Beside the bed field, not as a toast: "that bed is taken" is an
        // answer to what was filled in, and the field is what has to change.
        $this->admit($h, $nurse, $p2, $bed)
            ->assertNoRedirect()
            ->assertHasErrors(['bed_id']);
        $this->assertSame(1, Admission::count());
    }

    public function test_receptionist_cannot_admit(): void
    {
        $h = Hospital::factory()->create();
        $bed = $this->bed($h);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        // A receptionist has neither ipd.view nor ipd.manage: the admissions
        // component refuses at mount, and nothing is admitted.
        $this->actingAs($this->user($h, 'receptionist'));
        app(CurrentHospital::class)->set($h->id);

        Livewire::test(AdmissionsIndex::class)->assertForbidden();
        $this->assertDatabaseCount('admissions', 0);
    }

    public function test_summary_pdf_renders(): void
    {
        $h = Hospital::factory()->create();
        $nurse = $this->user($h, 'nurse');
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $bed = $this->bed($h);
        $admission = app(AdmissionService::class)->admit($patient, $bed, []);
        app(AdmissionService::class)->discharge($admission->fresh(), \App\Enums\AdmissionStatus::Discharged);

        $res = $this->actingAs($nurse)->get("/admin/admissions/{$admission->uuid}/summary");
        $res->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_admissions_are_tenant_isolated(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $bedB = $this->bed($b);
        $patientB = Patient::factory()->create(['hospital_id' => $b->id]);
        $admissionB = app(AdmissionService::class)->admit($patientB, $bedB, []);

        $nurseA = $this->user($a, 'nurse');
        $this->actingAs($nurseA)->get("/admin/admissions/{$admissionB->uuid}")->assertNotFound();

        // Same through the component: B's uuid does not resolve for A.
        app(CurrentHospital::class)->set($a->id);
        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($nurseA)->test(AdmissionShow::class, ['admission' => $admissionB->uuid]);
    }
}
