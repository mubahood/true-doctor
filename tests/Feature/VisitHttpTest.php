<?php

namespace Tests\Feature;

use App\Enums\VisitOutcome;
use App\Enums\VisitStage;
use App\Enums\VisitStatus;
use App\Livewire\Visits\Index as VisitsIndex;
use App\Livewire\Visits\Panels\Clinical as ClinicalPanel;
use App\Livewire\Visits\Panels\Vitals as VitalsPanel;
use App\Livewire\Visits\Show as VisitShow;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Services\VisitService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The OPD visit end to end. The workspace and every clinical write moved to
 * App\Livewire\Visits\Show + its lazy panels (Phase 3), so the former
 * vitals/clinical/transition POSTs are driven through the components here.
 */
class VisitHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        Livewire::withoutLazyLoading();   // the panels are #[Lazy]; test them mounted
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

    public function test_receptionist_opens_an_visit(): void
    {
        $h = Hospital::factory()->create();
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        // The classic create/store page is gone — the slide-over opens visits.
        $this->acting($h, 'receptionist');

        Livewire::test(VisitsIndex::class)
            ->call('create')
            ->set('patient_id', $patient->id)
            ->set('reason', 'Fever')
            ->call('save')
            ->assertHasNoErrors()
            // The dialog closes onto the list rather than navigating to the
            // new record — the next thing reception does is the next patient.
            ->assertNoRedirect()
            ->assertSet('showForm', false);

        $this->assertDatabaseHas('visits', ['patient_id' => $patient->id, 'status' => 'pending', 'stage' => 'ongoing']);
    }

    public function test_nurse_records_vitals_and_bmi_is_computed(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'nurse');
        $c = Visit::factory()->create(['hospital_id' => $h->id]);

        Livewire::test(VitalsPanel::class, ['visitId' => $c->id])
            ->set('weight', '70')
            ->set('height', '175')
            ->set('temperature', '37.0')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('22.86', (string) $c->fresh()->bmi); // 70/1.75^2
        $this->assertSame('70.00', (string) $c->fresh()->weight);
    }

    public function test_the_live_bmi_preview_uses_the_same_formula(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'nurse');
        $c = Visit::factory()->create(['hospital_id' => $h->id]);

        // The preview lives with the inputs, which are in the recording dialog.
        Livewire::test(VitalsPanel::class, ['visitId' => $c->id])
            ->call('openForm')
            ->set('weight', '70')
            ->set('height', '175')
            ->assertSee('22.86');

        // Nothing was persisted by the preview — BMI is a clinical write.
        $this->assertNull($c->fresh()->bmi);
    }

    public function test_out_of_range_vitals_are_rejected_inline(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'nurse');
        $c = Visit::factory()->create(['hospital_id' => $h->id]);

        Livewire::test(VitalsPanel::class, ['visitId' => $c->id])
            ->set('temperature', '99')
            ->set('blood_pressure', 'high')
            ->call('save')
            ->assertHasErrors(['temperature', 'blood_pressure']);

        $this->assertNull($c->fresh()->temperature);
    }

    public function test_nurse_cannot_write_diagnosis(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'nurse');
        $c = Visit::factory()->create(['hospital_id' => $h->id]);

        // The panel still renders (read-only) but the write is refused.
        Livewire::test(ClinicalPanel::class, ['visitId' => $c->id])
            ->assertOk()
            ->set('diagnosis', 'Malaria')
            ->call('save')
            ->assertForbidden();

        $this->assertNull($c->fresh()->diagnosis);
    }

    public function test_doctor_writes_diagnosis(): void
    {
        $h = Hospital::factory()->create();
        $doctor = $this->acting($h, 'doctor');
        $c = Visit::factory()->create(['hospital_id' => $h->id]);

        Livewire::test(ClinicalPanel::class, ['visitId' => $c->id])
            ->set('diagnosis', 'Malaria')
            ->set('doctor_remarks', 'ACT prescribed')
            ->set('doctor_user_id', $doctor->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('visit-updated');

        $this->assertSame('Malaria', $c->fresh()->diagnosis);
        $this->assertSame('ACT prescribed', $c->fresh()->doctor_remarks);
        $this->assertSame($doctor->id, $c->fresh()->doctor_user_id);
    }

    public function test_another_hospitals_doctor_cannot_be_assigned(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $foreign = $this->user($b, 'doctor');
        $this->acting($a, 'doctor');
        $c = Visit::factory()->create(['hospital_id' => $a->id]);

        Livewire::test(ClinicalPanel::class, ['visitId' => $c->id])
            ->set('doctor_user_id', $foreign->id)
            ->call('save')
            ->assertHasErrors('doctor_user_id');

        $this->assertNull($c->fresh()->doctor_user_id);
    }

    public function test_the_gate_advances_through_the_service(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'receptionist');
        $c = Visit::factory()->create(['hospital_id' => $h->id]);

        Livewire::test(VisitShow::class, ['visit' => $c])
            ->call('advance')
            ->assertHasNoErrors()
            ->assertDispatched('visit-updated');

        $this->assertSame(VisitStage::Billing, $c->fresh()->stage);
        $this->assertDatabaseHas('visit_status_histories', [
            'visit_id' => $c->id, 'to_stage' => 'billing',
        ]);
    }

    public function test_cancelling_records_the_reason(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'receptionist');
        $c = Visit::factory()->create(['hospital_id' => $h->id]);

        Livewire::test(VisitShow::class, ['visit' => $c])
            ->set('cancelNote', 'Patient left')
            ->call('cancelVisit')
            ->assertHasNoErrors();

        $this->assertSame(VisitOutcome::Cancelled, $c->fresh()->outcome);
        $this->assertDatabaseHas('visit_status_histories', [
            'visit_id' => $c->id, 'to_status' => 'completed', 'note' => 'Patient left',
        ]);
    }

    /** Cancelling costs a reason, on the page as everywhere else. */
    public function test_cancelling_without_a_reason_is_refused(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'receptionist');
        $c = Visit::factory()->create(['hospital_id' => $h->id]);

        Livewire::test(VisitShow::class, ['visit' => $c])
            ->set('cancelNote', '')
            ->call('cancelVisit')
            ->assertHasErrors('cancelNote');

        $this->assertTrue($c->fresh()->isOpen());
    }

    public function test_a_shut_gate_is_refused_with_a_toast(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'receptionist');
        $c = Visit::factory()->create(['hospital_id' => $h->id]);

        // Straight to Payment: there is no invoice, so the gate is shut.
        app(VisitService::class)->overrideState(
            $c, VisitStatus::Ongoing, VisitStage::Payment, null, null, 'Set up',
        );

        Livewire::test(VisitShow::class, ['visit' => $c->fresh()])
            ->call('advance')
            ->assertDispatched('toast');

        $this->assertSame(VisitStage::Payment, $c->fresh()->stage);
    }

    public function test_a_role_without_visits_manage_cannot_advance(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'nurse');           // nurse holds view + vitals, never manage
        $c = Visit::factory()->create(['hospital_id' => $h->id]);

        Livewire::test(VisitShow::class, ['visit' => $c])
            ->assertOk()
            ->call('advance')
            ->assertForbidden();

        $this->assertSame(VisitStage::Ongoing, $c->fresh()->stage);
    }
}
