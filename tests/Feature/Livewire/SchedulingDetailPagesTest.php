<?php

namespace Tests\Feature\Livewire;

use App\Enums\AppointmentStatus;
use App\Enums\BedStatus;
use App\Enums\Weekday;
use App\Livewire\Admissions\Board as OccupancyBoard;
use App\Livewire\Admissions\Show as AdmissionShow;
use App\Livewire\Appointments\Queue as AppointmentQueue;
use App\Livewire\Appointments\Show as AppointmentShow;
use App\Livewire\InsuranceClaims\Show as InsuranceClaimShow;
use App\Models\Admission;
use App\Models\Appointment;
use App\Models\Bed;
use App\Models\DoctorSchedule;
use App\Models\Hospital;
use App\Models\InsuranceProvider;
use App\Models\Patient;
use App\Models\Room;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Services\AppointmentService;
use App\Services\BillingService;
use App\Services\InsuranceService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Render / RBAC / tenancy for the five Phase 3 scheduling + inpatient + claims
 * screens (house rule 18), plus the two features the conversion adds back:
 * rescheduling an appointment and opening a visit from a checked-in one.
 * The behavioural assertions (bed exclusivity, discharge billing, append-only
 * logs, insurance payments) live with their domain suites.
 */
class SchedulingDetailPagesTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    /** A doctor with a Monday 09:00–17:00 window, and next Monday 09:00. */
    private function doctorWindow(Hospital $h): User
    {
        $doctor = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor', 'name' => 'Dr Zawedde']);
        DoctorSchedule::factory()->create([
            'hospital_id' => $h->id, 'user_id' => $doctor->id,
            'weekday' => Weekday::Monday, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'slot_minutes' => 30,
        ]);

        return $doctor;
    }

    private function nextMonday(int $hour = 9, int $minute = 0): Carbon
    {
        return Carbon::now()->next(Carbon::MONDAY)->setTime($hour, $minute);
    }

    private function appointment(Hospital $h, ?User $doctor = null): Appointment
    {
        $this->withHospital($h);
        $doctor ??= $this->doctorWindow($h);
        $patient = Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Ada', 'last_name' => 'Nakato']);

        return app(AppointmentService::class)->book([
            'patient_id' => $patient->id,
            'doctor_user_id' => $doctor->id,
            'scheduled_at' => $this->nextMonday()->format('Y-m-d H:i'),
            'duration_minutes' => 30,
            'reason' => 'Routine review',
        ]);
    }

    private function admission(Hospital $h, array $data = []): Admission
    {
        $this->withHospital($h);
        $ward = Ward::factory()->create(['hospital_id' => $h->id, 'name' => 'Maternity']);
        $bed = Bed::factory()->create(['hospital_id' => $h->id, 'ward_id' => $ward->id, 'name' => 'M-01', 'daily_charge' => '25.00']);
        $patient = Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Grace', 'last_name' => 'Auma']);

        return app(AdmissionService::class)->admit($patient, $bed, $data);
    }

    // ── Appointments\Show ──────────────────────────────────────

    public function test_appointment_show_renders_header_and_history(): void
    {
        $h = $this->hospital();
        $recep = $this->actingAsRole('receptionist', $h);
        $appt = $this->appointment($h);

        Livewire::actingAs($recep)->test(AppointmentShow::class, ['appointment' => $appt->uuid])
            ->assertOk()
            ->assertSet('appointmentId', $appt->id)
            ->assertSee('Ada Nakato')
            ->assertSee('Dr Zawedde')
            ->assertSee('Routine review')
            ->assertSee('Scheduled')
            ->assertSee('Booked');

        // Full-page smoke: route + layout + server-rendered title.
        $this->actingAs($recep)->get("/admin/appointments/{$appt->uuid}")
            ->assertOk()
            ->assertSee('<title>Ada Nakato · True-Doctor</title>', false);
    }

    public function test_appointment_show_is_forbidden_without_appointments_view(): void
    {
        $h = $this->hospital();
        $appt = $this->appointment($h);

        $stripped = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $stripped->syncRoles([]);
        $this->withHospital($h);

        Livewire::actingAs($stripped)->test(AppointmentShow::class, ['appointment' => $appt->uuid])->assertForbidden();
    }

    public function test_appointment_show_is_tenant_isolated(): void
    {
        $a = $this->hospital();
        $b = $this->hospital();
        $apptB = $this->appointment($b);
        $adminA = $this->actingAsRole('hospital_admin', $a);

        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($adminA)->test(AppointmentShow::class, ['appointment' => $apptB->uuid]);
    }

    // ── Reschedule (restores the deleted classic edit page) ────

    public function test_reschedule_moves_the_appointment_to_a_new_slot(): void
    {
        $h = $this->hospital();
        $recep = $this->actingAsRole('receptionist', $h);
        $appt = $this->appointment($h);
        $room = Room::factory()->create(['hospital_id' => $h->id, 'name' => 'Consult 2']);

        $newSlot = $this->nextMonday(10, 30);

        Livewire::actingAs($recep)->test(AppointmentShow::class, ['appointment' => $appt->uuid])
            ->call('openReschedule')
            ->assertSet('showReschedule', true)
            ->assertSet('duration_minutes', 30)
            ->set('scheduled_at', $newSlot->format('Y-m-d\TH:i'))
            ->set('duration_minutes', 60)
            ->set('room_id', $room->id)
            ->call('reschedule')
            ->assertHasNoErrors()
            ->assertSet('showReschedule', false)
            ->assertDispatched('toast', type: 'success');

        $appt->refresh();
        $this->assertSame($newSlot->format('Y-m-d H:i'), $appt->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame(60, $appt->duration_minutes);
        $this->assertSame($room->id, $appt->room_id);
        $this->assertSame($newSlot->copy()->addMinutes(60)->format('H:i'), $appt->ends_at->format('H:i'));

        // The move is recorded on the appointment's own history.
        $this->assertStringContainsString('Rescheduled', (string) $appt->history()->first()?->note);
    }

    public function test_reschedule_outside_the_doctors_window_is_refused_inline(): void
    {
        $h = $this->hospital();
        $recep = $this->actingAsRole('receptionist', $h);
        $appt = $this->appointment($h);

        Livewire::actingAs($recep)->test(AppointmentShow::class, ['appointment' => $appt->uuid])
            ->call('openReschedule')
            ->set('scheduled_at', $this->nextMonday(21, 0)->format('Y-m-d\TH:i'))
            ->call('reschedule')
            ->assertHasErrors('scheduled_at')
            ->assertSet('showReschedule', true);

        $this->assertSame($this->nextMonday()->format('H:i'), $appt->fresh()->scheduled_at->format('H:i'));
    }

    public function test_nurse_cannot_reschedule(): void
    {
        $h = $this->hospital();
        $appt = $this->appointment($h);
        $nurse = $this->actingAsRole('nurse', $h);

        Livewire::actingAs($nurse)->test(AppointmentShow::class, ['appointment' => $appt->uuid])
            ->call('openReschedule')
            ->assertForbidden();
    }

    // ── Open visit ─────────────────────────────────────────

    public function test_open_visit_creates_a_visit_linked_to_the_appointment(): void
    {
        $h = $this->hospital();
        $recep = $this->actingAsRole('receptionist', $h);
        $doctor = $this->doctorWindow($h);
        $appt = $this->appointment($h, $doctor);

        app(AppointmentService::class)->transition($appt, AppointmentStatus::CheckedIn, $recep->id);

        Livewire::actingAs($recep)->test(AppointmentShow::class, ['appointment' => $appt->uuid])
            ->assertSee('Open visit')
            ->call('openVisit')
            ->assertRedirect(route('admin.visits.show', Visit::firstOrFail()));

        $visit = Visit::firstOrFail();
        $this->assertSame($appt->id, $visit->appointment_id);
        $this->assertSame($appt->patient_id, $visit->patient_id);
        $this->assertSame($doctor->id, $visit->doctor_user_id);
        $this->assertSame('Routine review', $visit->reason);
    }

    public function test_open_visit_is_refused_before_check_in(): void
    {
        $h = $this->hospital();
        $recep = $this->actingAsRole('receptionist', $h);
        $appt = $this->appointment($h);

        Livewire::actingAs($recep)->test(AppointmentShow::class, ['appointment' => $appt->uuid])
            ->call('openVisit')
            ->assertNoRedirect()
            ->assertDispatched('toast', type: 'error');

        $this->assertDatabaseCount('visits', 0);
    }

    // ── Appointments\Queue ─────────────────────────────────────

    public function test_queue_lists_todays_open_appointments_only(): void
    {
        $h = $this->hospital();
        $recep = $this->actingAsRole('receptionist', $h);
        $doctor = $this->doctorWindow($h);
        $patient = Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Ada', 'last_name' => 'Nakato']);

        $today = Appointment::factory()->create([
            'hospital_id' => $h->id, 'patient_id' => $patient->id, 'doctor_user_id' => $doctor->id,
            'scheduled_at' => now()->setTime(9, 0), 'ends_at' => now()->setTime(9, 30),
            'status' => AppointmentStatus::Scheduled,
        ]);
        // Finished today: no longer on the board.
        Appointment::factory()->create([
            'hospital_id' => $h->id, 'patient_id' => $patient->id, 'doctor_user_id' => $doctor->id,
            'scheduled_at' => now()->setTime(8, 0), 'ends_at' => now()->setTime(8, 30),
            'status' => AppointmentStatus::Completed,
        ]);
        // Tomorrow: not today's queue.
        Appointment::factory()->create([
            'hospital_id' => $h->id, 'patient_id' => $patient->id, 'doctor_user_id' => $doctor->id,
            'scheduled_at' => now()->addDay()->setTime(9, 0), 'ends_at' => now()->addDay()->setTime(9, 30),
            'status' => AppointmentStatus::Scheduled,
        ]);

        $component = Livewire::actingAs($recep)->test(AppointmentQueue::class)->assertOk();
        $this->assertSame([$today->id], $component->instance()->rows->pluck('id')->all());

        $this->actingAs($recep)->get('/admin/appointments/queue')
            ->assertOk()
            ->assertSee('Check-in queue')
            ->assertSee('Ada Nakato');
    }

    public function test_queue_row_transition_advances_the_appointment(): void
    {
        $h = $this->hospital();
        $recep = $this->actingAsRole('receptionist', $h);
        $doctor = $this->doctorWindow($h);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $appt = Appointment::factory()->create([
            'hospital_id' => $h->id, 'patient_id' => $patient->id, 'doctor_user_id' => $doctor->id,
            'scheduled_at' => now()->setTime(9, 0), 'ends_at' => now()->setTime(9, 30),
            'status' => AppointmentStatus::Scheduled,
        ]);

        // `advance` now, shared with the diary through ActsOnAppointments —
        // one vocabulary, so the two boards cannot drift apart.
        Livewire::actingAs($recep)->test(AppointmentQueue::class)
            ->call('advance', $appt->id, 'checked_in')
            ->assertDispatched('toast', type: 'success');

        $this->assertSame(AppointmentStatus::CheckedIn, $appt->fresh()->status);
    }

    public function test_queue_shows_an_empty_state_and_is_gated(): void
    {
        $h = $this->hospital();
        $recep = $this->actingAsRole('receptionist', $h);

        Livewire::actingAs($recep)->test(AppointmentQueue::class)->assertOk()->assertSee('The queue is empty');

        $stripped = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $stripped->syncRoles([]);
        Livewire::actingAs($stripped)->test(AppointmentQueue::class)->assertForbidden();
    }

    // ── Admissions\Show ────────────────────────────────────────

    public function test_admission_show_renders_summary_and_lazy_panels(): void
    {
        $h = $this->hospital(['currency' => 'UGX']);
        $nurse = $this->actingAsRole('nurse', $h);
        $admission = $this->admission($h);

        Livewire::actingAs($nurse)->test(AdmissionShow::class, ['admission' => $admission->uuid])
            ->assertOk()
            ->assertSet('admissionId', $admission->id)
            ->assertSee('Grace Auma')
            ->assertSee('Maternity')
            ->assertSee('M-01')
            ->assertSee('Admitted')
            ->call('openTransfer')
            ->assertSet('showTransfer', true)
            ->call('openDischarge')
            ->assertSet('showDischarge', true)
            ->assertSet('outcome', 'discharged');

        $this->actingAs($nurse)->get("/admin/admissions/{$admission->uuid}")
            ->assertOk()
            ->assertSee('<title>Grace Auma · True-Doctor</title>', false);
    }

    public function test_admission_show_is_forbidden_without_ipd_view(): void
    {
        $h = $this->hospital();
        $admission = $this->admission($h);
        $recep = $this->actingAsRole('receptionist', $h);

        Livewire::actingAs($recep)->test(AdmissionShow::class, ['admission' => $admission->uuid])->assertForbidden();
    }

    // ── Admissions\Board ───────────────────────────────────────

    public function test_board_shows_wards_beds_occupancy_and_rates(): void
    {
        $h = $this->hospital(['currency' => 'UGX']);
        $nurse = $this->actingAsRole('nurse', $h);
        $admission = $this->admission($h);

        // A second, free bed in the same ward, with a nightly rate on show.
        Bed::factory()->create([
            'hospital_id' => $h->id, 'ward_id' => $admission->bed->ward_id,
            'name' => 'M-02', 'daily_charge' => '25.00', 'status' => BedStatus::Available,
        ]);

        Livewire::actingAs($nurse)->test(OccupancyBoard::class)
            ->assertOk()
            ->assertSee('Maternity')
            ->assertSee('1/2 occupied')
            ->assertSee('M-01')
            ->assertSee('M-02')
            ->assertSee('Grace Auma')
            // The tile opens the bed OVER the board now. The link to the
            // admission moved inside that dialog, where it is one of several
            // things to do rather than the only thing a click can do.
            ->assertSeeHtml('wire:click="peek('.$admission->bed_id.')"');

        $this->actingAs($nurse)->get('/admin/occupancy')
            ->assertOk()
            ->assertSee('<title>Occupancy board · True-Doctor</title>', false);
    }

    public function test_board_is_forbidden_without_ipd_view(): void
    {
        $h = $this->hospital();
        $recep = $this->actingAsRole('receptionist', $h);

        Livewire::actingAs($recep)->test(OccupancyBoard::class)->assertForbidden();
    }

    public function test_board_is_tenant_isolated(): void
    {
        $a = $this->hospital();
        $b = $this->hospital();
        $this->admission($b);
        $nurseA = $this->actingAsRole('nurse', $a);

        Livewire::actingAs($nurseA)->test(OccupancyBoard::class)
            ->assertOk()
            ->assertDontSee('Grace Auma')
            ->assertDontSee('Maternity');
    }

    // ── InsuranceClaims\Show ───────────────────────────────────

    public function test_claim_show_renders_summary_and_links_the_invoice(): void
    {
        $h = $this->hospital(['currency' => 'UGX']);
        $acct = $this->actingAsRole('accountant', $h);

        $patient = Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Ada', 'last_name' => 'Nakato']);
        $visit = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $patient->id]);
        $service = Service::factory()->create(['hospital_id' => $h->id, 'price' => '100.00']);
        app(BillingService::class)->orderService($visit->fresh(), $service->id, 1);
        $invoice = app(BillingService::class)->generateInvoice($visit->fresh(), '0.00', null);
        $provider = InsuranceProvider::factory()->create(['hospital_id' => $h->id, 'name' => 'Jubilee Health']);

        $claim = app(InsuranceService::class)->createClaim([
            'patient_id' => $patient->id, 'insurance_provider_id' => $provider->id,
            'invoice_id' => $invoice->id, 'amount' => '100.00',
        ]);

        Livewire::actingAs($acct)->test(InsuranceClaimShow::class, ['insuranceClaim' => $claim->uuid])
            ->assertOk()
            ->assertSet('claimId', $claim->id)
            ->assertSee($claim->claim_no)
            ->assertSee('Ada Nakato')
            ->assertSee('Jubilee Health')
            ->assertSee('Draft')
            ->assertSee($invoice->invoice_no)
            ->assertSeeHtml(route('admin.invoices.show', $invoice));

        $this->actingAs($acct)->get("/admin/insurance-claims/{$claim->uuid}")
            ->assertOk()
            ->assertSee('<title>'.$claim->claim_no.' · True-Doctor</title>', false);
    }

    public function test_claim_show_is_forbidden_for_a_doctor(): void
    {
        $h = $this->hospital(['currency' => 'UGX']);
        $acct = $this->actingAsRole('accountant', $h);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $provider = InsuranceProvider::factory()->create(['hospital_id' => $h->id]);
        // A claim is raised against a bill, and a bill belongs to a visit.
        $visit = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $patient->id]);
        app(BillingService::class)->orderService($visit->fresh(), Service::factory()->create(['hospital_id' => $h->id, 'price' => '20.00'])->id, 1);
        $invoice = app(BillingService::class)->generateInvoice($visit->fresh(), '0.00', null);
        $claim = app(InsuranceService::class)->createClaim([
            'patient_id' => $patient->id, 'insurance_provider_id' => $provider->id,
            'invoice_id' => $invoice->id, 'amount' => '20.00',
        ]);
        $this->assertNotNull($acct);

        $doctor = $this->actingAsRole('doctor', $h);
        Livewire::actingAs($doctor)->test(InsuranceClaimShow::class, ['insuranceClaim' => $claim->uuid])->assertForbidden();
    }
}
