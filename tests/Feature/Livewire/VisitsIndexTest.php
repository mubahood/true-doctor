<?php

namespace Tests\Feature\Livewire;

use App\Enums\AppointmentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\VisitOutcome;
use App\Enums\VisitStage;
use App\Enums\VisitStatus;
use App\Livewire\Visits\Index;
use App\Models\Appointment;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VisitsIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function actingDoctor(Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor']);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    private function actingReceptionist(Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'receptionist']);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    public function test_it_lists_and_searches_visits(): void
    {
        $h = Hospital::factory()->create();
        $doctor = $this->actingDoctor($h);
        $p1 = Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Findable']);
        $p2 = Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Hiddenone']);
        Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $p1->id, 'doctor_user_id' => $doctor->id]);
        Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $p2->id, 'doctor_user_id' => $doctor->id]);

        Livewire::test(Index::class)
            ->assertSee('Findable')
            ->set('search', 'Findable')
            ->assertSee('Findable')
            ->assertDontSee('Hiddenone');
    }

    public function test_a_role_without_visit_view_is_forbidden(): void
    {
        $h = Hospital::factory()->create();
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor']);
        $u->syncRoles([]);
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        Livewire::test(Index::class)->assertForbidden();
    }

    /** The words on the buttons: you create a visit here, you do not "open" one. */
    public function test_the_create_affordances_say_create(): void
    {
        $this->actingReceptionist(Hospital::factory()->create());

        Livewire::test(Index::class)
            ->assertSee('New visit')
            ->assertDontSee('Open visit')
            ->call('create')
            ->assertSee('Create visit')
            ->call('setTab', 'intake')
            ->assertSee('Register & create visit');
    }

    public function test_modal_opens_an_visit_via_service(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);
        $doctor = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor']);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        Livewire::test(Index::class)
            ->call('create')
            ->assertSet('showForm', true)
            ->set('patient_id', $patient->id)
            ->set('reason', 'Fever')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('visits', ['patient_id' => $patient->id]);
    }

    public function test_modal_requires_a_patient(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        Livewire::test(Index::class)
            ->call('create')
            ->call('save')
            ->assertHasErrors('patient_id');
    }

    // ── What the desk actually has ───────────────────────────────────────

    /**
     * No department, no doctor.
     *
     * Neither belongs to a visit — the ORDERS raised on it carry the department
     * that does the work, and the doctor is recorded with the clinical notes by
     * whoever sees the patient. Reception opens a visit before either is known,
     * so asking was asking a question nobody at the desk could answer.
     */
    public function test_the_dialog_does_not_ask_for_a_department_or_a_doctor(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        $html = Livewire::test(Index::class)->call('create')->html();

        $this->assertStringNotContainsString('resource="departments"', $html);
        $this->assertStringNotContainsString('resource="doctors"', $html);
        $this->assertStringNotContainsString('Where is it going', $html);
    }

    /** What the desk DOES have is the patient in front of them. */
    public function test_vitals_taken_at_the_desk_are_recorded_with_the_visit(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        Livewire::test(Index::class)
            ->call('create')
            ->set('patient_id', $patient->id)
            ->set('temperature', '38.4')
            ->set('blood_pressure', '128/84')
            ->set('pulse', '92')
            ->set('spo2', '97')
            ->set('weight', '70')
            ->set('height', '175')
            ->call('save')
            ->assertHasNoErrors();

        $visit = Visit::where('patient_id', $patient->id)->firstOrFail();

        $this->assertSame('38.4', (string) $visit->temperature);
        $this->assertSame('128/84', $visit->blood_pressure);
        $this->assertSame(92, $visit->pulse);
        $this->assertNotNull($visit->vitals_recorded_at, 'nothing stamped when the readings were taken');

        // BMI comes from VisitService, the same method the vitals panel uses.
        $this->assertSame('22.86', (string) $visit->bmi);
    }

    /** A reading nobody took is left alone, not written as zero. */
    public function test_a_visit_with_no_vitals_records_none(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        Livewire::test(Index::class)
            ->call('create')
            ->set('patient_id', $patient->id)
            ->call('save')
            ->assertHasNoErrors();

        $visit = Visit::where('patient_id', $patient->id)->firstOrFail();

        $this->assertNull($visit->temperature);
        $this->assertNull($visit->vitals_recorded_at);
    }

    public function test_a_reading_outside_the_possible_range_is_refused(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        Livewire::test(Index::class)
            ->call('create')
            ->set('patient_id', $patient->id)
            ->set('temperature', '58')
            ->call('save')
            ->assertHasErrors('temperature');

        $this->assertSame(0, Visit::count());
    }

    // ── Pending, or already under way ────────────────────────────────────

    public function test_a_visit_opens_pending_by_default(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        Livewire::test(Index::class)
            ->call('create')
            ->assertSet('start_now', false)
            ->set('patient_id', $patient->id)
            ->call('save');

        $this->assertSame(VisitStatus::Pending, Visit::firstOrFail()->status);
    }

    /**
     * Starting it goes through VisitService::start(), not a status write.
     *
     * That is the only thing that moves a visit to Ongoing, and it is what puts
     * the move in the trail — a visit that simply appears mid-flight has a
     * history that begins in the middle of itself.
     */
    public function test_starting_it_at_the_desk_is_recorded_as_a_move(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        Livewire::test(Index::class)
            ->call('create')
            ->set('patient_id', $patient->id)
            ->set('start_now', true)
            ->call('save')
            ->assertHasNoErrors();

        $visit = Visit::firstOrFail();

        $this->assertSame(VisitStatus::Ongoing, $visit->status);
        $this->assertSame(\App\Enums\VisitStage::Ongoing, $visit->stage);
        $this->assertGreaterThan(0, $visit->history()->count(), 'the move was not written into the trail');
    }

    // ── What we already know about the patient ───────────────────────────

    /** An open visit is the thing most worth knowing before opening another. */
    public function test_an_open_visit_is_shown_with_a_way_back_to_it(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        $patient = Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Ada']);
        $open = Visit::factory()->create([
            'hospital_id' => $h->id, 'patient_id' => $patient->id, 'status' => VisitStatus::Ongoing,
        ]);

        $component = Livewire::test(Index::class)
            ->call('create')
            ->call('picked', 'patient_id', $patient->id)
            ->assertSee('Already has an open visit')
            ->assertSee($open->visit_no);

        $this->assertSame($open->visit_no, $component->get('patientBrief')['open_visit']['visit_no']);

        $component->call('openExisting')
            ->assertSet('showForm', false)
            ->assertRedirect(route('admin.visits.show', $open->uuid));
    }

    /** A finished visit is history, not a warning. */
    public function test_a_finished_visit_is_not_reported_as_open(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        Visit::factory()->create([
            'hospital_id' => $h->id, 'patient_id' => $patient->id, 'status' => VisitStatus::Completed, 'outcome' => VisitOutcome::Closed,
        ]);

        $brief = Livewire::test(Index::class)
            ->call('create')
            ->call('picked', 'patient_id', $patient->id)
            ->get('patientBrief');

        $this->assertNull($brief['open_visit']);
    }

    public function test_money_still_owed_is_shown_at_the_desk(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $earlier = Visit::factory()->create([
            'hospital_id' => $h->id, 'patient_id' => $patient->id, 'status' => VisitStatus::Completed, 'outcome' => VisitOutcome::Closed,
        ]);
        Invoice::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'hospital_id' => $h->id, 'visit_id' => $earlier->id, 'patient_id' => $patient->id,
            'invoice_no' => 'INV-TEST-1', 'currency' => 'UGX',
            'subtotal' => '50000', 'tax_total' => '0', 'discount' => '0',
            'total' => '50000', 'amount_paid' => '30000', 'balance' => '20000',
            'status' => InvoiceStatus::PartiallyPaid, 'issued_at' => now(),
        ]);

        $brief = Livewire::test(Index::class)
            ->call('create')
            ->call('picked', 'patient_id', $patient->id)
            ->get('patientBrief');

        $this->assertSame('20000.00', $brief['owed']);
    }

    // ── The appointment this visit fulfils ───────────────────────────────

    /** Linking inherits what was already agreed rather than asking for it twice. */
    public function test_todays_booking_is_offered_and_fills_the_rest_in(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $dept = Department::factory()->create(['hospital_id' => $h->id]);
        $doctor = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor']);

        $booking = Appointment::factory()->create([
            'hospital_id' => $h->id, 'patient_id' => $patient->id,
            'doctor_user_id' => $doctor->id, 'department_id' => $dept->id,
            'scheduled_at' => now()->setTime(10, 30), 'status' => AppointmentStatus::Scheduled,
            'reason' => 'Antenatal check-up',
        ]);

        $component = Livewire::test(Index::class)
            ->call('create')
            ->call('picked', 'patient_id', $patient->id)
            ->assertSee('Booked today')
            ->call('linkAppointment')
            ->assertSet('appointment_id', $booking->id)
            ->assertSet('reason', 'Antenatal check-up');

        $component->call('save');

        $visit = Visit::where('patient_id', $patient->id)->firstOrFail();
        $this->assertSame($booking->id, $visit->appointment_id, 'the visit did not carry the booking');
    }

    /** One appointment, one visit — a booking already attended is not offered. */
    public function test_a_booking_that_already_has_a_visit_is_not_offered_again(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $booking = Appointment::factory()->create([
            'hospital_id' => $h->id, 'patient_id' => $patient->id,
            'scheduled_at' => now()->setTime(9, 0), 'status' => AppointmentStatus::Scheduled,
        ]);
        Visit::factory()->create([
            'hospital_id' => $h->id, 'patient_id' => $patient->id,
            'appointment_id' => $booking->id, 'status' => VisitStatus::Completed, 'outcome' => VisitOutcome::Closed,
        ]);

        $brief = Livewire::test(Index::class)
            ->call('create')
            ->call('picked', 'patient_id', $patient->id)
            ->get('patientBrief');

        $this->assertNull($brief['appointment'], 'an attended booking was offered a second time');
    }

    /**
     * The last word is the database, not the form.
     *
     * Two receptionists, a double-click or a retried request all used to make
     * two visits — and two bills — for one attendance. The unique index on
     * visits.appointment_id is what makes that impossible rather than unlikely.
     */
    public function test_one_appointment_cannot_become_two_visits(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $booking = Appointment::factory()->create([
            'hospital_id' => $h->id, 'patient_id' => $patient->id,
            'scheduled_at' => now(), 'status' => AppointmentStatus::Scheduled,
        ]);

        Visit::factory()->create([
            'hospital_id' => $h->id, 'patient_id' => $patient->id, 'appointment_id' => $booking->id,
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        Visit::factory()->create([
            'hospital_id' => $h->id, 'patient_id' => $patient->id, 'appointment_id' => $booking->id,
        ]);
    }

    /** Walk-ins have no booking, and many of them can be open at once. */
    public function test_visits_without_an_appointment_are_unaffected(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        foreach (range(1, 3) as $ignored) {
            Visit::factory()->create([
                'hospital_id' => $h->id, 'patient_id' => $patient->id, 'appointment_id' => null,
            ]);
        }

        $this->assertSame(3, Visit::where('patient_id', $patient->id)->count());
    }

    /** The browser is not trusted with someone else's appointment. */
    public function test_an_appointment_of_another_patient_is_refused(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        $mine = Patient::factory()->create(['hospital_id' => $h->id]);
        $theirs = Patient::factory()->create(['hospital_id' => $h->id]);
        $booking = Appointment::factory()->create([
            'hospital_id' => $h->id, 'patient_id' => $theirs->id,
            'scheduled_at' => now(), 'status' => AppointmentStatus::Scheduled,
        ]);

        Livewire::test(Index::class)
            ->call('create')
            ->call('picked', 'patient_id', $mine->id)
            ->set('appointment_id', $booking->id)
            ->call('save')
            ->assertHasErrors('appointment_id');

        $this->assertSame(0, Visit::where('patient_id', $mine->id)->count());
    }

    /** Changing patient drops a link that was made for the previous one. */
    public function test_changing_the_patient_drops_the_appointment_link(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        $first = Patient::factory()->create(['hospital_id' => $h->id]);
        $second = Patient::factory()->create(['hospital_id' => $h->id]);
        Appointment::factory()->create([
            'hospital_id' => $h->id, 'patient_id' => $first->id,
            'scheduled_at' => now()->setTime(11, 0), 'status' => AppointmentStatus::Scheduled,
        ]);

        Livewire::test(Index::class)
            ->call('create')
            ->call('picked', 'patient_id', $first->id)
            ->call('linkAppointment')
            ->assertNotSet('appointment_id', null)
            ->call('picked', 'patient_id', $second->id)
            ->assertSet('appointment_id', null);
    }

    // ── Why they came ────────────────────────────────────────────────────

    public function test_the_reasons_the_hospital_actually_writes_come_first(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        foreach (range(1, 3) as $ignored) {
            Visit::factory()->create([
                'hospital_id' => $h->id, 'patient_id' => $patient->id, 'reason' => 'Snake bite',
            ]);
        }

        $suggestions = Livewire::test(Index::class)->call('create')->get('reasonSuggestions');

        $this->assertSame('Snake bite', $suggestions[0], "the hospital's own words did not come first");
        $this->assertContains('Follow-up visit', $suggestions, 'the curated set did not top it up');
    }

    /**
     * A reason written once is not a house phrase.
     *
     * Live data proved this: a seeded lorem-ipsum string led the list, because
     * counting every reason equally lets one typo teach the hospital its own
     * noise.
     */
    public function test_a_reason_used_only_once_is_not_offered(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        Visit::factory()->create([
            'hospital_id' => $h->id, 'patient_id' => $patient->id, 'reason' => 'Qwertyuiop asdfgh',
        ]);

        $suggestions = Livewire::test(Index::class)->call('create')->get('reasonSuggestions');

        $this->assertNotContains('Qwertyuiop asdfgh', $suggestions);
    }

    /** "0 yrs" is how a computer describes a baby, not how a clinician does. */
    public function test_an_infants_age_is_given_in_months(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        $baby = Patient::factory()->create([
            'hospital_id' => $h->id, 'dob' => now()->subMonths(7)->toDateString(),
        ]);
        $adult = Patient::factory()->create([
            'hospital_id' => $h->id, 'dob' => now()->subYears(34)->toDateString(),
        ]);

        $component = Livewire::test(Index::class)->call('create');

        $this->assertStringContainsString('7 mo',
            $component->call('picked', 'patient_id', $baby->id)->get('patientBrief')['meta']);

        $this->assertStringContainsString('34 yrs',
            $component->call('picked', 'patient_id', $adult->id)->get('patientBrief')['meta']);
    }

    /**
     * A reason is a list now — see PhraseFieldTest for the whole behaviour.
     * What this one holds is the guard: only a phrase the form OFFERED can be
     * written, so the pills cannot be used to put arbitrary text in a record.
     */
    public function test_only_a_reason_that_was_offered_can_be_clicked_in(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        Livewire::test(Index::class)
            ->call('create')
            ->call('usePhrase', 'reason', 'Follow-up visit')
            ->assertSet('reason', 'Follow-up visit')
            ->call('usePhrase', 'reason', 'Give me a free operation')
            ->assertSet('reason', 'Follow-up visit');
    }

    // ── Reopening is clean ───────────────────────────────────────────────

    /** No pick, no brief and no stale picker survives a close and reopen. */
    public function test_reopening_the_form_starts_clean(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        $component = Livewire::test(Index::class)->call('create');
        $first = $component->get('formNonce');

        $component->call('picked', 'patient_id', $patient->id)
            ->set('reason', 'Something')
            ->call('create')
            ->assertSet('patient_id', null)
            ->assertSet('reason', null);

        $this->assertSame([], $component->get('patientBrief'));
        $this->assertGreaterThan($first, $component->get('formNonce'), 'the pickers were not remounted');
    }

    // ── The table says both things ───────────────────────────────────────

    private function at(Hospital $h, VisitStatus $status, VisitStage $stage, ?VisitOutcome $outcome = null): Visit
    {
        return Visit::factory()->create([
            'hospital_id' => $h->id,
            'patient_id' => Patient::factory()->create(['hospital_id' => $h->id])->id,
            'status' => $status, 'stage' => $stage, 'outcome' => $outcome,
        ]);
    }

    /**
     * Status and stage each get their own column.
     *
     * One badge built from the pair reads well in a dialog, where there is one
     * visit. In a list the reader is comparing rows, and folding two facts
     * into one word means a visit sitting at Billing and one that was
     * cancelled look like the same kind of thing.
     */
    public function test_the_table_shows_status_and_stage_separately(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);
        $this->at($h, VisitStatus::Ongoing, VisitStage::Billing);

        $html = Livewire::test(Index::class)->html();

        $this->assertStringContainsString('>Status ', $html, 'the table has no status column');
        $this->assertStringContainsString('>Stage ', $html, 'the table has no stage column');
        $this->assertStringContainsString('Ongoing', $html);
        $this->assertStringContainsString('Billing', $html);
    }

    /** A finished visit shows how it ended, not the word "completed" twice. */
    public function test_a_cancelled_visit_reads_as_cancelled(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);
        $this->at($h, VisitStatus::Completed, VisitStage::Ongoing, VisitOutcome::Cancelled);

        Livewire::test(Index::class)->assertSee('Cancelled');
    }

    /** A visit nobody has started is not AT a stage yet. */
    public function test_a_pending_visit_shows_no_stage(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);
        $this->at($h, VisitStatus::Pending, VisitStage::Ongoing);

        Livewire::test(Index::class)->assertSee('Not started');
    }

    // ── One filter per question ──────────────────────────────────────────

    public function test_the_two_filters_narrow_independently(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        $billing = $this->at($h, VisitStatus::Ongoing, VisitStage::Billing);
        $caring = $this->at($h, VisitStatus::Ongoing, VisitStage::Ongoing);
        $cancelled = $this->at($h, VisitStatus::Completed, VisitStage::Ongoing, VisitOutcome::Cancelled);
        $closed = $this->at($h, VisitStatus::Completed, VisitStage::Completed, VisitOutcome::Closed);

        // Status alone.
        Livewire::test(Index::class)
            ->set('status', VisitOutcome::Cancelled->value)
            ->assertSee($cancelled->visit_no)
            ->assertDontSee($closed->visit_no)
            ->assertDontSee($billing->visit_no);

        // Stage alone.
        Livewire::test(Index::class)
            ->set('stage', VisitStage::Billing->value)
            ->assertSee($billing->visit_no)
            ->assertDontSee($caring->visit_no);

        // Both at once — the common case, which one combined list cannot say.
        Livewire::test(Index::class)
            ->set('status', VisitStatus::Ongoing->value)
            ->set('stage', VisitStage::Ongoing->value)
            ->assertSee($caring->visit_no)
            ->assertDontSee($billing->visit_no)
            ->assertDontSee($cancelled->visit_no);
    }

    /** Completed and cancelled are told apart, though both are "completed". */
    public function test_completed_does_not_include_cancelled(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        $cancelled = $this->at($h, VisitStatus::Completed, VisitStage::Ongoing, VisitOutcome::Cancelled);
        $closed = $this->at($h, VisitStatus::Completed, VisitStage::Completed, VisitOutcome::Closed);

        Livewire::test(Index::class)
            ->set('status', VisitOutcome::Closed->value)
            ->assertSee($closed->visit_no)
            ->assertDontSee($cancelled->visit_no);
    }

    /** A new filter must never strand the reader on an empty page. */
    public function test_filtering_returns_to_the_first_page(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        foreach (range(1, 25) as $ignored) {
            $this->at($h, VisitStatus::Ongoing, VisitStage::Ongoing);
        }

        Livewire::test(Index::class)
            ->call('gotoPage', 2)
            ->set('stage', VisitStage::Billing->value)
            ->assertSet('paginators.page', 1);
    }

    // ── Sorting, like every other table ──────────────────────────────────

    public function test_the_table_sorts_and_refuses_columns_that_are_not_columns(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        Livewire::test(Index::class)
            ->call('sortBy', 'visit_no')
            ->assertSet('sortField', 'visit_no')
            ->assertSet('sortDir', 'asc')
            ->call('sortBy', 'visit_no')
            ->assertSet('sortDir', 'desc')
            ->call('sortBy', 'stage')
            ->assertSet('sortField', 'stage')
            // A doctor's name lives on another table; sorting by it is refused.
            ->call('sortBy', 'doctor')
            ->assertSet('sortField', 'stage');
    }

    public function test_sorting_by_stage_actually_orders_the_rows(): void
    {
        $h = Hospital::factory()->create();
        $this->actingReceptionist($h);

        $payment = $this->at($h, VisitStatus::Ongoing, VisitStage::Payment);
        $billing = $this->at($h, VisitStatus::Ongoing, VisitStage::Billing);

        $html = Livewire::test(Index::class)->call('sortBy', 'stage')->html();

        $this->assertLessThan(
            strpos($html, $payment->visit_no),
            strpos($html, $billing->visit_no),
            'sorting by stage did not order the rows',
        );
    }
}
