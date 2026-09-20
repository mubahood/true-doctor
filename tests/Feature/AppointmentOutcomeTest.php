<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Weekday;
use App\Livewire\Appointments\Index as Appointments;
use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\Hospital;
use App\Models\Order;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\AppointmentService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Finishing an appointment means saying what was done at it.
 *
 * "Completed" was a button. It moved a status and recorded nothing: no
 * findings for whoever sees the patient next, no services, nothing to bill —
 * a hospital could see a patient all day and invoice none of it.
 *
 * An appointment now ends the way every other piece of clinical work in this
 * system ends: as an ORDER on a visit, of type Consultation, carrying the
 * doctor's report and the services provided. None of that machinery is new;
 * this is the appointment being made to use the visit module (docs/orders.md).
 */
class AppointmentOutcomeTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $doctor;

    private Patient $patient;

    private Service $consult;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        Carbon::setTestNow(Carbon::parse('2026-09-16 09:10:00'));

        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->doctor = User::factory()->create([
            'hospital_id' => $this->hospital->id, 'role' => 'doctor', 'name' => 'Dr Kasujja',
        ]);
        $this->doctor->syncSpatieRole();
        $this->actingAs($this->doctor);

        $this->patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);

        $this->consult = Service::factory()->create([
            'hospital_id' => $this->hospital->id, 'name' => 'Consultation', 'price' => '30000.00',
        ]);

        DoctorSchedule::create([
            'hospital_id' => $this->hospital->id, 'user_id' => $this->doctor->id,
            'weekday' => Weekday::Wednesday->value, 'start_time' => '08:00', 'end_time' => '17:00',
            'slot_minutes' => 30, 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function arrived(): Appointment
    {
        $service = app(AppointmentService::class);

        $appointment = $service->book([
            'patient_id' => $this->patient->id,
            'doctor_user_id' => $this->doctor->id,
            'scheduled_at' => '2026-09-16 10:00',
            'duration_minutes' => 30,
            'source' => 'walk_in',
        ], $this->doctor->id);

        return $service->transition($appointment, AppointmentStatus::CheckedIn, $this->doctor->id);
    }

    private function page(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Appointments::class);
    }

    // ── What finishing one now writes ────────────────────────────────────

    public function test_the_outcome_is_a_consultation_order_on_a_visit(): void
    {
        $appointment = $this->arrived();

        $order = app(AppointmentService::class)->recordOutcome(
            $appointment, 'Malaria. Treated and advised to return if fever persists.', [], $this->doctor->id,
        );

        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(OrderType::Consultation, $order->type);
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame('Malaria. Treated and advised to return if fever persists.', $order->report);
        $this->assertNotNull($order->report_updated_at);

        $visit = $appointment->fresh()->visit;
        $this->assertNotNull($visit, 'the appointment must be seen in a visit');
        $this->assertSame($visit->id, $order->visit_id);
        $this->assertSame($this->patient->id, $visit->patient_id);
    }

    public function test_the_services_provided_are_charged_at_the_price_list(): void
    {
        $lab = Service::factory()->create([
            'hospital_id' => $this->hospital->id, 'name' => 'Malaria RDT', 'price' => '12000.00',
        ]);

        $order = app(AppointmentService::class)->recordOutcome(
            $this->arrived(),
            'Fever. RDT positive.',
            [['kind' => 'service', 'id' => $this->consult->id, 'quantity' => '1'], ['kind' => 'service', 'id' => $lab->id, 'quantity' => '2']],
            $this->doctor->id,
        );

        $this->assertCount(2, $order->items);

        $rdt = $order->items->firstWhere('service_id', $lab->id);
        $this->assertSame('12000.00', (string) $rdt->unit_price, 'the price is the price list, not the form');
        $this->assertSame(0, bccomp((string) $rdt->quantity, '2', 2));
    }

    /** The whole point: the work done is on the bill. */
    public function test_what_was_provided_reaches_the_visits_charges(): void
    {
        $appointment = $this->arrived();

        app(AppointmentService::class)->recordOutcome(
            $appointment, 'Seen.', [['kind' => 'service', 'id' => $this->consult->id, 'quantity' => '1']], $this->doctor->id,
        );

        $visit = $appointment->fresh()->visit;
        $charged = app(\App\Services\BillingService::class)->totalsFor($visit);

        $this->assertSame(0, bccomp($charged['total'], '30000.00', 2), 'the consultation must be billable');
    }

    /** A consultation with nothing added is free, not broken. */
    public function test_an_outcome_with_no_services_still_records(): void
    {
        $order = app(AppointmentService::class)->recordOutcome($this->arrived(), 'Advice only.', [], $this->doctor->id);

        $this->assertCount(0, $order->items);
        $this->assertSame('Advice only.', $order->report);
    }

    public function test_recording_completes_the_appointment(): void
    {
        $appointment = $this->arrived();

        app(AppointmentService::class)->recordOutcome($appointment, 'Seen.', [], $this->doctor->id);

        $this->assertSame(AppointmentStatus::Completed, $appointment->fresh()->status);
    }

    /**
     * Checked in cannot reach Completed in one step, and should not: the
     * patient was with the doctor, and the trail has to say so.
     */
    public function test_the_trail_shows_the_patient_was_actually_seen(): void
    {
        $appointment = $this->arrived();

        app(AppointmentService::class)->recordOutcome($appointment, 'Seen.', [], $this->doctor->id);

        $steps = $appointment->fresh()->history->sortBy('id')->pluck('to_status')->map(fn ($s) => $s->value)->all();

        $this->assertSame(['scheduled', 'checked_in', 'in_progress', 'completed'], $steps);
    }

    /** A visit already opened from this appointment is used, not duplicated. */
    public function test_an_appointment_is_only_ever_seen_in_one_visit(): void
    {
        $appointment = $this->arrived();
        $service = app(AppointmentService::class);

        $first = $service->visitFor($appointment, $this->doctor->id);
        $order = $service->recordOutcome($appointment, 'Seen.', [], $this->doctor->id);

        $this->assertSame($first->id, $order->visit_id);
        $this->assertSame(1, Visit::where('appointment_id', $appointment->id)->count());
    }

    // ── When it may not be recorded ──────────────────────────────────────

    public function test_nothing_can_be_reported_before_the_patient_arrives(): void
    {
        $appointment = app(AppointmentService::class)->book([
            'patient_id' => $this->patient->id, 'doctor_user_id' => $this->doctor->id,
            'scheduled_at' => '2026-09-16 11:00', 'duration_minutes' => 30, 'source' => 'walk_in',
        ], $this->doctor->id);

        $this->expectExceptionMessage('arrive');
        app(AppointmentService::class)->recordOutcome($appointment, 'Seen.', [], $this->doctor->id);
    }

    public function test_an_appointment_that_has_ended_cannot_be_reported_on(): void
    {
        $appointment = $this->arrived();
        app(AppointmentService::class)->recordOutcome($appointment, 'Seen.', [], $this->doctor->id);

        $this->expectExceptionMessage('already completed');
        app(AppointmentService::class)->recordOutcome($appointment->fresh(), 'Again.', [], $this->doctor->id);
    }

    /** Nothing is written at all if one line is wrong. */
    public function test_an_unknown_service_rolls_the_whole_thing_back(): void
    {
        $appointment = $this->arrived();

        try {
            app(AppointmentService::class)->recordOutcome(
                $appointment, 'Seen.',
                [['kind' => 'service', 'id' => $this->consult->id], ['kind' => 'service', 'id' => 99999]],
                $this->doctor->id,
            );
            $this->fail('an unknown service should have stopped it');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->fresh()->status);
        $this->assertSame(0, Order::count());
    }

    // ── From the diary ───────────────────────────────────────────────────

    public function test_completing_from_the_row_opens_the_dialog_instead(): void
    {
        $appointment = $this->arrived();
        app(AppointmentService::class)->transition($appointment, AppointmentStatus::InProgress, $this->doctor->id);

        $this->page()->call('advance', $appointment->id, 'completed')
            ->assertSet('showOutcome', true)
            ->assertSet('outcomeId', $appointment->id);

        $this->assertSame(AppointmentStatus::InProgress, $appointment->fresh()->status, 'nothing yet');
    }

    public function test_the_dialog_will_not_submit_without_a_report(): void
    {
        $appointment = $this->arrived();

        $this->page()->call('openOutcome', $appointment->id)
            ->call('saveOutcome')
            ->assertHasErrors('report');

        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->fresh()->status);
    }

    public function test_submitting_writes_the_report_and_charges_the_services(): void
    {
        $appointment = $this->arrived();

        $this->page()->call('openOutcome', $appointment->id)
            ->call('picked', 'provided_service_id', $this->consult->id)
            ->set('report', 'Reviewed. Continue current treatment.')
            ->call('saveOutcome')
            ->assertHasNoErrors()
            ->assertSet('showOutcome', false);

        $order = Order::firstOrFail();
        $this->assertSame('Reviewed. Continue current treatment.', $order->report);
        $this->assertCount(1, $order->items);
        $this->assertSame(AppointmentStatus::Completed, $appointment->fresh()->status);
    }

    /** Choosing the same service twice means two of it, not two lines. */
    public function test_picking_a_service_twice_raises_its_quantity(): void
    {
        $page = $this->page()->call('openOutcome', $this->arrived()->id)
            ->call('picked', 'provided_service_id', $this->consult->id)
            ->call('picked', 'provided_service_id', $this->consult->id);

        $provided = $page->get('provided');
        $this->assertCount(1, $provided);
        $this->assertSame(0, bccomp($provided[0]['quantity'], '2', 2));
    }

    public function test_a_line_can_be_taken_off_again(): void
    {
        $page = $this->page()->call('openOutcome', $this->arrived()->id)
            ->call('picked', 'provided_service_id', $this->consult->id)
            ->call('removeProvided', 0);

        $this->assertSame([], $page->get('provided'));
    }

    public function test_the_running_total_is_what_will_be_charged(): void
    {
        $page = $this->page()->call('openOutcome', $this->arrived()->id)
            ->call('picked', 'provided_service_id', $this->consult->id)
            ->set('provided.0.quantity', '3');

        $this->assertSame(0, bccomp($page->instance()->providedTotal(), '90000', 2));
    }

    public function test_backing_out_charges_nothing(): void
    {
        $appointment = $this->arrived();

        $this->page()->call('openOutcome', $appointment->id)
            ->call('picked', 'provided_service_id', $this->consult->id)
            ->call('closeOutcome')
            ->assertSet('showOutcome', false)
            ->assertSet('provided', []);

        $this->assertSame(0, Order::count(), 'nothing may be billed until it is submitted');
        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->fresh()->status);
    }

    public function test_a_service_from_another_hospital_is_ignored(): void
    {
        $other = Hospital::factory()->create();
        $theirs = Service::factory()->create(['hospital_id' => $other->id, 'name' => 'Their service']);

        $page = $this->page()->call('openOutcome', $this->arrived()->id)
            ->call('picked', 'provided_service_id', $theirs->id);

        $this->assertSame([], $page->get('provided'));
    }

    // ── A product off the shelf, not only a service ──────────────────────

    private function shelf(string $name, string $price, string $quantity): \App\Models\StockItem
    {
        return \App\Models\StockItem::factory()->create([
            'hospital_id' => $this->hospital->id,
            'name' => $name,
            'sale_price' => $price,
            'current_quantity' => $quantity,
            'is_active' => true,
        ]);
    }

    /**
     * A consultation is rarely only advice: something is usually handed over.
     * A product is charged the same way a service is AND leaves the shelf, in
     * the same transaction, which is the order model's job (docs/orders.md).
     */
    public function test_a_product_is_charged_and_taken_off_the_shelf(): void
    {
        $drug = $this->shelf('Paracetamol 500mg', '500.00', '40');

        $order = app(AppointmentService::class)->recordOutcome(
            $this->arrived(), 'Fever. Paracetamol given.',
            [['kind' => 'product', 'id' => $drug->id, 'quantity' => '12']],
            $this->doctor->id,
        );

        $line = $order->items->firstOrFail();
        $this->assertSame($drug->id, $line->stock_item_id);
        $this->assertSame(0, bccomp((string) $line->unit_price, '500.00', 2));
        $this->assertSame(0, bccomp((string) $drug->fresh()->current_quantity, '28', 2), 'the shelf must go down');
    }

    public function test_services_and_products_sit_on_one_order(): void
    {
        $drug = $this->shelf('Paracetamol 500mg', '500.00', '40');

        $order = app(AppointmentService::class)->recordOutcome(
            $this->arrived(), 'Seen.',
            [
                ['kind' => 'service', 'id' => $this->consult->id, 'quantity' => '1'],
                ['kind' => 'product', 'id' => $drug->id, 'quantity' => '2'],
            ],
            $this->doctor->id,
        );

        $this->assertCount(2, $order->items);
        $this->assertSame(0, bccomp(
            app(\App\Services\BillingService::class)->totalsFor($order->visit)['total'],
            '31000.00',
            2,
        ));
    }

    /** Promising more than the pharmacy has must take nothing and charge nothing. */
    public function test_more_than_the_shelf_holds_rolls_everything_back(): void
    {
        $drug = $this->shelf('Paracetamol 500mg', '500.00', '3');
        $appointment = $this->arrived();

        try {
            app(AppointmentService::class)->recordOutcome(
                $appointment, 'Seen.',
                [['kind' => 'product', 'id' => $drug->id, 'quantity' => '10']],
                $this->doctor->id,
            );
            $this->fail('the shelf does not hold ten');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(0, bccomp((string) $drug->fresh()->current_quantity, '3', 2));
        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->fresh()->status);
        $this->assertSame(0, Order::count());
    }

    public function test_the_dialog_offers_both_catalogues(): void
    {
        $drug = $this->shelf('Paracetamol 500mg', '500.00', '40');

        $page = $this->page()->call('openOutcome', $this->arrived()->id)
            ->call('picked', 'provided_service_id', $this->consult->id)
            ->call('picked', 'provided_product_id', $drug->id);

        $provided = $page->get('provided');

        $this->assertSame(['service', 'product'], array_column($provided, 'kind'));
        $this->assertSame('40', $provided[1]['stock'], 'the shelf count is shown before anything is promised');
    }

    // ── Whole units ──────────────────────────────────────────────────────

    /**
     * You give two tablets or one dressing. The spinner used to step by a
     * hundredth, so one press turned 1 into 1.01 and the bill read UShs15,300
     * for a UShs15,000 visit.
     */
    public function test_a_fractional_quantity_is_refused(): void
    {
        $appointment = $this->arrived();

        $this->page()->call('openOutcome', $appointment->id)
            ->call('picked', 'provided_service_id', $this->consult->id)
            ->set('provided.0.quantity', '1.02')
            ->set('report', 'Seen.')
            ->call('saveOutcome')
            ->assertHasErrors('provided.0.quantity');

        $this->assertSame(0, Order::count());
        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->fresh()->status);
    }

    public function test_none_of_something_is_refused_too(): void
    {
        $this->page()->call('openOutcome', $this->arrived()->id)
            ->call('picked', 'provided_service_id', $this->consult->id)
            ->set('provided.0.quantity', '0')
            ->set('report', 'Seen.')
            ->call('saveOutcome')
            ->assertHasErrors('provided.0.quantity');
    }

    public function test_picking_the_same_thing_twice_counts_in_whole_units(): void
    {
        $page = $this->page()->call('openOutcome', $this->arrived()->id)
            ->call('picked', 'provided_service_id', $this->consult->id)
            ->call('picked', 'provided_service_id', $this->consult->id)
            ->call('picked', 'provided_service_id', $this->consult->id);

        $this->assertSame('3', $page->get('provided')[0]['quantity']);
    }

    // ── Saving halfway ───────────────────────────────────────────────────

    /**
     * A consultation is not always written up in one sitting, and the choice
     * is between letting the doctor save halfway and watching them lose it.
     */
    public function test_saving_without_completing_leaves_the_patient_with_the_doctor(): void
    {
        $appointment = $this->arrived();

        $this->page()->call('openOutcome', $appointment->id)
            ->set('completeIt', false)
            ->set('report', 'Examined. Awaiting the lab.')
            ->call('saveOutcome')
            ->assertHasNoErrors();

        $this->assertSame(AppointmentStatus::InProgress, $appointment->fresh()->status);
        $this->assertSame(OrderStatus::InProgress, Order::firstOrFail()->status);
    }

    /** Reopening shows the work already done, not a blank form over it. */
    public function test_reopening_picks_up_where_it_was_left(): void
    {
        $appointment = $this->arrived();

        $this->page()->call('openOutcome', $appointment->id)
            ->set('completeIt', false)
            ->call('picked', 'provided_service_id', $this->consult->id)
            ->set('report', 'Examined. Awaiting the lab.')
            ->call('saveOutcome');

        $again = $this->page()->call('openOutcome', $appointment->id);

        $this->assertSame('Examined. Awaiting the lab.', $again->get('report'));
        $this->assertCount(1, $again->get('provided'));
        $this->assertSame($this->consult->id, $again->get('provided')[0]['id']);
    }

    public function test_finishing_later_uses_the_same_order(): void
    {
        $appointment = $this->arrived();
        $service = app(AppointmentService::class);

        $service->recordOutcome($appointment, 'Examined.', [], $this->doctor->id, complete: false);
        $service->recordOutcome($appointment->fresh(), 'Examined. Lab clear.',
            [['kind' => 'service', 'id' => $this->consult->id, 'quantity' => '1']], $this->doctor->id);

        $this->assertSame(1, Order::count(), 'one attendance, one consultation');
        $this->assertSame('Examined. Lab clear.', Order::firstOrFail()->report);
        $this->assertSame(AppointmentStatus::Completed, $appointment->fresh()->status);
    }

    /** Taking a line off between sittings takes it off the bill. */
    public function test_a_line_dropped_before_finishing_is_not_charged(): void
    {
        $appointment = $this->arrived();
        $service = app(AppointmentService::class);

        $service->recordOutcome($appointment, 'Examined.',
            [['kind' => 'service', 'id' => $this->consult->id, 'quantity' => '1']], $this->doctor->id, complete: false);

        $service->recordOutcome($appointment->fresh(), 'Examined. Nothing charged after all.', [], $this->doctor->id);

        // The row stays, cancelled — a bill keeps its own history — but it is
        // off the charges, which is what "not charged" means.
        $order = Order::firstOrFail();
        $this->assertSame(\App\Enums\OrderItemStatus::Cancelled, $order->items->firstOrFail()->status);
        $this->assertSame(0, bccomp(
            app(\App\Services\BillingService::class)->totalsFor($order->visit)['total'], '0', 2,
        ));
    }

    /** …and adding it back afterwards is a new line, not the cancelled one. */
    public function test_a_line_put_back_after_being_dropped_is_charged_again(): void
    {
        $appointment = $this->arrived();
        $service = app(AppointmentService::class);
        $line = [['kind' => 'service', 'id' => $this->consult->id, 'quantity' => '1']];

        $service->recordOutcome($appointment, 'Examined.', $line, $this->doctor->id, complete: false);
        $service->recordOutcome($appointment->fresh(), 'Examined.', [], $this->doctor->id, complete: false);
        $service->recordOutcome($appointment->fresh(), 'Examined.', $line, $this->doctor->id);

        $order = Order::firstOrFail();
        $live = $order->items->reject(fn ($i) => $i->status === \App\Enums\OrderItemStatus::Cancelled);

        $this->assertCount(1, $live);
        $this->assertSame(0, bccomp(
            app(\App\Services\BillingService::class)->totalsFor($order->visit)['total'], '30000.00', 2,
        ));
    }

    /** And a product put back on the list puts its stock back too. */
    public function test_a_product_dropped_before_finishing_returns_to_the_shelf(): void
    {
        $drug = $this->shelf('Paracetamol 500mg', '500.00', '40');
        $appointment = $this->arrived();
        $service = app(AppointmentService::class);

        $service->recordOutcome($appointment, 'Examined.',
            [['kind' => 'product', 'id' => $drug->id, 'quantity' => '5']], $this->doctor->id, complete: false);
        $this->assertSame(0, bccomp((string) $drug->fresh()->current_quantity, '35', 2));

        $service->recordOutcome($appointment->fresh(), 'Examined. Not given after all.', [], $this->doctor->id);

        $this->assertSame(0, bccomp((string) $drug->fresh()->current_quantity, '40', 2), 'the shelf must be put back');
    }

    public function test_changing_a_quantity_between_sittings_moves_the_stock(): void
    {
        $drug = $this->shelf('Paracetamol 500mg', '500.00', '40');
        $appointment = $this->arrived();
        $service = app(AppointmentService::class);

        $service->recordOutcome($appointment, 'Examined.',
            [['kind' => 'product', 'id' => $drug->id, 'quantity' => '5']], $this->doctor->id, complete: false);

        $service->recordOutcome($appointment->fresh(), 'Examined.',
            [['kind' => 'product', 'id' => $drug->id, 'quantity' => '8']], $this->doctor->id);

        $this->assertSame(0, bccomp((string) $drug->fresh()->current_quantity, '32', 2));
        $this->assertCount(1, Order::firstOrFail()->items, 'still one line, not two');
    }

    // ── What the row actually offers ─────────────────────────────────────

    /**
     * The row has to SAY "Record outcome".
     *
     * Every test above drove the component directly, so all of them passed
     * while the button rendered as an empty box: `…outcome@else` is not a
     * directive to Blade, because the character before `@` is a word
     * character, so the label never reached the page. Behaviour was right and
     * the page was blank. These assert the page.
     */
    /**
     * Read the labels off the row's own action buttons.
     *
     * Not `assertSee`: a closed dialog's title is still in the DOM, so page
     * text cannot tell "the row offers this" from "a hidden dialog mentions
     * it" — which is the difference these tests exist to check.
     *
     * @return list<string>
     */
    private function rowButtonLabels(): array
    {
        preg_match_all(
            '/<button\b[^>]*class="[^"]*tb-nextbtn[^"]*"[^>]*>(.*?)<\/button>/s',
            $this->page()->html(),
            $matches,
        );

        return array_map(
            fn (string $label) => trim(strip_tags(preg_replace('/<!--.*?-->/s', '', $label) ?? '')),
            $matches[1],
        );
    }

    public function test_a_checked_in_row_offers_the_report(): void
    {
        $this->arrived();

        $this->assertSame(['Record outcome'], $this->rowButtonLabels());
    }

    /**
     * And it goes STRAIGHT there. Making the desk press "In progress" first
     * and the report afterwards is the bare status button this was meant to
     * replace — recordOutcome walks it through In progress itself.
     */
    public function test_the_report_is_one_click_from_checked_in(): void
    {
        $appointment = $this->arrived();

        $this->page()->call('openOutcome', $appointment->id)->assertSet('showOutcome', true);
    }

    public function test_a_row_before_arrival_offers_the_next_step_instead(): void
    {
        app(AppointmentService::class)->book([
            'patient_id' => $this->patient->id, 'doctor_user_id' => $this->doctor->id,
            'scheduled_at' => '2026-09-16 11:00', 'duration_minutes' => 30, 'source' => 'walk_in',
        ], $this->doctor->id);

        $this->assertSame(['Confirmed'], $this->rowButtonLabels());
    }

    public function test_a_finished_row_offers_neither(): void
    {
        $appointment = $this->arrived();
        app(AppointmentService::class)->recordOutcome($appointment, 'Seen.', [], $this->doctor->id);

        $this->assertSame([], $this->rowButtonLabels(), 'a finished appointment has nothing left to press');
    }

    /** No button anywhere may render with nothing written on it. */
    public function test_no_action_button_on_the_row_is_empty(): void
    {
        $this->arrived();

        $labels = $this->rowButtonLabels();

        $this->assertNotEmpty($labels, 'the row drew no action button at all');

        foreach ($labels as $label) {
            $this->assertNotSame('', $label, 'an action button rendered with no label on it');
        }
    }

    // ── Who may say what was found ───────────────────────────────────────

    /**
     * Writing a clinical report is a clinical act. Whoever books and checks
     * somebody in is not, by that alone, the person who says what was found.
     */
    public function test_a_receptionist_cannot_write_the_report(): void
    {
        $appointment = $this->arrived();

        $clerk = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'receptionist']);
        $clerk->syncSpatieRole();
        $this->actingAs($clerk);

        Livewire::test(Appointments::class)
            ->call('openOutcome', $appointment->id)
            ->set('report', 'All fine.')
            ->call('saveOutcome')
            ->assertForbidden();

        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->fresh()->status);
        $this->assertSame(0, Order::count());
    }

    public function test_another_hospitals_appointment_cannot_be_reported_on(): void
    {
        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $theirDoctor = User::factory()->create(['hospital_id' => $other->id, 'role' => 'doctor']);
        $theirPatient = Patient::factory()->create(['hospital_id' => $other->id]);
        DoctorSchedule::create([
            'hospital_id' => $other->id, 'user_id' => $theirDoctor->id,
            'weekday' => Weekday::Wednesday->value, 'start_time' => '08:00', 'end_time' => '17:00',
            'slot_minutes' => 30, 'is_active' => true,
        ]);
        $theirs = app(AppointmentService::class)->book([
            'patient_id' => $theirPatient->id, 'doctor_user_id' => $theirDoctor->id,
            'scheduled_at' => '2026-09-16 12:00', 'duration_minutes' => 30, 'source' => 'walk_in',
        ], null);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->page()->call('openOutcome', $theirs->id);
    }
}
