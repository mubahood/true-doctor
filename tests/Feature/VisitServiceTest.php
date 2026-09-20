<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\VisitOutcome;
use App\Enums\VisitStage;
use App\Enums\VisitStatus;
use App\Exceptions\InvalidVisitTransitionException;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Patient;
use App\Models\Visit;
use App\Models\VisitStatusHistory;
use App\Services\BillingService;
use App\Services\OrderService;
use App\Services\VisitService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VisitServiceTest extends TestCase
{
    use RefreshDatabase;

    private VisitService $svc;

    private Hospital $hospital;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(VisitService::class);
        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);
        $this->patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
    }

    public function test_opens_pending_with_a_generated_number_and_opening_history(): void
    {
        $visit = $this->svc->open(['patient_id' => $this->patient->id]);

        // Opened, not started: a visit becomes Ongoing when the first piece of
        // work lands on it, not when somebody books it in.
        $this->assertSame(VisitStatus::Pending, $visit->status);
        $this->assertSame(VisitStage::Ongoing, $visit->stage);
        $this->assertNull($visit->outcome);

        $this->assertMatchesRegularExpression('/^V-\d{8}-\d{3}$/', $visit->visit_no);
        $this->assertDatabaseHas('visit_status_histories', [
            'visit_id' => $visit->id, 'from_status' => null, 'to_status' => 'pending',
        ]);
    }

    public function test_number_sequence_increments_per_day(): void
    {
        $a = $this->svc->open(['patient_id' => $this->patient->id]);
        $b = $this->svc->open(['patient_id' => $this->patient->id]);

        $this->assertStringEndsWith('-001', $a->visit_no);
        $this->assertStringEndsWith('-002', $b->visit_no);
    }

    public function test_records_vitals_and_computes_bmi(): void
    {
        $c = $this->svc->open(['patient_id' => $this->patient->id]);

        $this->svc->recordVitals($c, ['weight' => 80, 'height' => 178, 'temperature' => 36.8, 'blood_pressure' => '120/80']);

        // 80 / (1.78^2) = 25.25
        $this->assertSame('25.25', (string) $c->fresh()->bmi);
        $this->assertNotNull($c->fresh()->vitals_recorded_at);
    }

    public function test_bmi_is_null_without_both_measurements(): void
    {
        $this->assertNull($this->svc->computeBmi(80.0, null));
        $this->assertNull($this->svc->computeBmi(null, 178.0));
        $this->assertNull($this->svc->computeBmi(80.0, 0.0));
        $this->assertSame(25.25, $this->svc->computeBmi(80.0, 178.0));
    }

    // ── The gates ────────────────────────────────────────────────────────

    /** An open order is the one thing that must not be billed around. */
    public function test_a_visit_cannot_reach_billing_while_an_order_is_open(): void
    {
        $visit = $this->started();
        $this->order($visit, OrderStatus::Pending);

        $gate = $this->svc->readiness($visit->fresh());
        $this->assertFalse($gate['ready']);
        $this->assertSame('1 order is still open.', $gate['blocker']);

        $this->expectException(InvalidVisitTransitionException::class);
        $this->svc->advance($visit->fresh());
    }

    public function test_the_blocker_counts_the_orders(): void
    {
        $visit = $this->started();
        $this->order($visit, OrderStatus::Pending);
        $this->order($visit, OrderStatus::InProgress);
        $this->order($visit, OrderStatus::Completed);

        $this->assertSame('2 orders are still open.', $this->svc->readiness($visit->fresh())['blocker']);
    }

    public function test_finishing_every_order_opens_the_gate_to_billing(): void
    {
        $visit = $this->started();
        $order = $this->order($visit, OrderStatus::Pending);

        $order->update(['status' => OrderStatus::Completed]);

        $gate = $this->svc->readiness($visit->fresh());
        $this->assertTrue($gate['ready']);
        $this->assertSame('Ready for billing', $gate['label']);

        $this->svc->advance($visit->fresh());
        $this->assertSame(VisitStage::Billing, $visit->fresh()->stage);
    }

    /** A visit with no orders at all has nothing holding it. */
    public function test_a_visit_with_no_orders_may_go_straight_to_billing(): void
    {
        $visit = $this->started();

        $this->assertTrue($this->svc->readiness($visit)['ready']);
    }

    public function test_billing_waits_for_an_invoice(): void
    {
        $visit = $this->atStage(VisitStage::Billing);

        $gate = $this->svc->readiness($visit);
        $this->assertFalse($gate['ready']);
        $this->assertSame('No invoice has been generated yet.', $gate['blocker']);
    }

    public function test_an_invoice_opens_the_gate_to_payment(): void
    {
        $visit = $this->atStage(VisitStage::Billing);
        $this->invoice($visit, total: '10000', paid: '0');

        $this->assertTrue($this->svc->readiness($visit->fresh())['ready']);

        $this->svc->advance($visit->fresh());
        $this->assertSame(VisitStage::Payment, $visit->fresh()->stage);
    }

    /**
     * The rule nothing used to enforce.
     *
     * A visit could be marked completed with money still owed on it, and then
     * nobody was looking for it any more.
     */
    public function test_a_visit_cannot_leave_payment_owing_money(): void
    {
        $visit = $this->atStage(VisitStage::Payment);
        $this->invoice($visit, total: '10000', paid: '4000');

        $gate = $this->svc->readiness($visit->fresh());
        $this->assertFalse($gate['ready']);
        $this->assertStringContainsString('still outstanding', (string) $gate['blocker']);

        $this->expectException(InvalidVisitTransitionException::class);
        $this->svc->advance($visit->fresh());
    }

    // ── What happens by itself ───────────────────────────────────────────

    /** Nobody presses "start"; the first piece of work does it. */
    public function test_the_first_order_starts_the_visit(): void
    {
        $visit = $this->svc->open(['patient_id' => $this->patient->id]);
        $this->assertSame(VisitStatus::Pending, $visit->status);

        app(OrderService::class)->place($visit, OrderType::Procedure, 'Wound dressing');

        $this->assertSame(VisitStatus::Ongoing, $visit->fresh()->status);
        $this->assertSame(VisitStage::Ongoing, $visit->fresh()->stage);
    }

    /** And nobody presses "complete"; the last shilling does it. */
    public function test_paying_the_balance_in_full_completes_the_visit(): void
    {
        $visit = $this->atStage(VisitStage::Payment);
        $invoice = $this->invoice($visit, total: '10000', paid: '0');

        app(BillingService::class)->recordPayment($invoice, PaymentMethod::Cash, '10000');

        $fresh = $visit->fresh();
        $this->assertSame(VisitStatus::Completed, $fresh->status);
        $this->assertSame(VisitStage::Completed, $fresh->stage);
        $this->assertSame(VisitOutcome::Closed, $fresh->outcome);
        $this->assertNotNull($fresh->completed_at);
    }

    /** Half of it does not. */
    public function test_a_part_payment_leaves_the_visit_where_it_is(): void
    {
        $visit = $this->atStage(VisitStage::Payment);
        $invoice = $this->invoice($visit, total: '10000', paid: '0');

        app(BillingService::class)->recordPayment($invoice, PaymentMethod::Cash, '4000');

        $this->assertSame(VisitStage::Payment, $visit->fresh()->stage);
        $this->assertTrue($visit->fresh()->isOpen());
    }

    public function test_reconcile_is_safe_to_call_twice(): void
    {
        $visit = $this->started();
        $before = $visit->history()->count();

        $this->svc->reconcile($visit);
        $this->svc->reconcile($visit);

        $this->assertSame($before, $visit->fresh()->history()->count(), 'reconcile wrote a row with nothing to say');
    }

    // ── Calling it off ───────────────────────────────────────────────────

    public function test_cancelling_finishes_the_visit_with_that_outcome(): void
    {
        $visit = $this->started();

        $this->svc->cancel($visit, null, 'Patient left');

        $fresh = $visit->fresh();
        $this->assertSame(VisitStatus::Completed, $fresh->status);
        $this->assertSame(VisitOutcome::Cancelled, $fresh->outcome);
        $this->assertTrue($fresh->wasCancelled());
        $this->assertFalse($fresh->isOpen());
        $this->assertSame('Cancelled', $fresh->stateLabel());
        $this->assertSame('Patient left', $fresh->history()->latest('id')->first()->note);
    }

    public function test_a_finished_visit_cannot_be_cancelled_again(): void
    {
        $visit = $this->started();
        $this->svc->cancel($visit, null, 'Once');

        $this->expectException(InvalidVisitTransitionException::class);
        $this->svc->cancel($visit->fresh(), null, 'Twice');
    }

    public function test_a_finished_visit_cannot_be_advanced(): void
    {
        $visit = $this->started();
        $this->svc->cancel($visit, null, 'Done');

        $this->expectException(InvalidVisitTransitionException::class);
        $this->svc->advance($visit->fresh());
    }

    // ── The escape hatch ─────────────────────────────────────────────────

    public function test_an_override_puts_a_visit_anywhere_and_says_so(): void
    {
        $visit = $this->started();

        $this->svc->overrideState($visit, VisitStatus::Ongoing, VisitStage::Payment, null, null, 'Paid up front');

        $fresh = $visit->fresh();
        $this->assertSame(VisitStage::Payment, $fresh->stage);

        $entry = $fresh->history()->latest('id')->first();
        $this->assertTrue($entry->is_override);
        $this->assertSame('Paid up front', $entry->note);
        $this->assertSame('ongoing', $entry->from_stage);
        $this->assertSame('payment', $entry->to_stage);
    }

    public function test_an_override_without_a_reason_is_refused(): void
    {
        $visit = $this->started();

        $this->expectException(\RuntimeException::class);
        $this->svc->overrideState($visit, VisitStatus::Completed, VisitStage::Completed, VisitOutcome::Closed, null, '  ');
    }

    /** The thing the hatch exists for: a visit finished by mistake. */
    public function test_a_finished_visit_can_be_put_back(): void
    {
        $visit = $this->started();
        $this->svc->cancel($visit, null, 'Wrong patient');

        $this->svc->overrideState($visit->fresh(), VisitStatus::Ongoing, VisitStage::Ongoing, null, null, 'Cancelled the wrong one');

        $fresh = $visit->fresh();
        $this->assertSame(VisitStatus::Ongoing, $fresh->status);
        $this->assertNull($fresh->outcome, 'a reopened visit kept the outcome of the one that was finished');
        $this->assertNull($fresh->completed_at, 'a reopened visit kept a finishing time');
    }

    /** A finished visit always has an outcome, and an open one never does. */
    public function test_the_outcome_follows_the_status(): void
    {
        $visit = $this->started();

        $this->svc->overrideState($visit, VisitStatus::Completed, VisitStage::Completed, null, null, 'Finish it');
        $this->assertSame(VisitOutcome::Closed, $visit->fresh()->outcome, 'a finished visit was left with no outcome');

        $this->svc->overrideState($visit->fresh(), VisitStatus::Ongoing, VisitStage::Billing, VisitOutcome::Cancelled, null, 'Back');
        $this->assertNull($visit->fresh()->outcome, 'an open visit was left with an outcome');
    }

    public function test_an_override_to_where_it_already_is_writes_nothing(): void
    {
        $visit = $this->started();
        $before = $visit->history()->count();

        $this->svc->overrideState($visit, $visit->status, $visit->stage, $visit->outcome, null, 'No change');

        $this->assertSame($before, $visit->fresh()->history()->count());
    }

    public function test_an_ordinary_move_is_not_flagged_as_an_override(): void
    {
        $visit = $this->started();
        $this->svc->advance($visit);

        foreach ($visit->fresh()->history as $entry) {
            $this->assertFalse($entry->is_override, 'an ordinary move was recorded as an override');
        }
    }

    // ── What a receptionist reads ────────────────────────────────────────

    /**
     * One badge, built from two fields.
     *
     * Status and stage answer different questions, but nobody wants to read
     * two badges: a visit is Pending, or Ongoing, or at Billing, or Cancelled.
     */
    public function test_one_word_is_shown_for_the_pair(): void
    {
        $visit = $this->svc->open(['patient_id' => $this->patient->id]);
        $this->assertSame('Pending', $visit->stateLabel());

        $visit = $this->started();
        $this->assertSame('Ongoing', $visit->stateLabel());

        $this->svc->overrideState($visit, VisitStatus::Ongoing, VisitStage::Billing, null, null, 'x');
        $this->assertSame('Billing', $visit->fresh()->stateLabel());

        $this->svc->overrideState($visit->fresh(), VisitStatus::Completed, VisitStage::Completed, VisitOutcome::Closed, null, 'x');
        $this->assertSame('Completed', $visit->fresh()->stateLabel());

        $this->svc->overrideState($visit->fresh(), VisitStatus::Completed, VisitStage::Ongoing, VisitOutcome::Cancelled, null, 'x');
        $this->assertSame('Cancelled', $visit->fresh()->stateLabel());
    }

    /** No stage or status may be labelled with a word the desk must learn. */
    public function test_no_label_uses_clinical_jargon(): void
    {
        $labels = [
            ...array_map(fn (VisitStatus $c) => $c->label(), VisitStatus::cases()),
            ...array_map(fn (VisitStage $c) => $c->label(), VisitStage::cases()),
            ...array_map(fn (VisitOutcome $c) => $c->label(), VisitOutcome::cases()),
        ];

        foreach ($labels as $label) {
            foreach (['triage', 'consultation', 'registration'] as $jargon) {
                $this->assertStringNotContainsStringIgnoringCase($jargon, $label);
            }
        }
    }

    /**
     * The trail is not rewritten, so it must still read.
     *
     * Rows written before status and stage were separated say things like
     * "triage → consultation". That is a true record of what happened, and
     * editing it to fit today's words would be falsifying an audit table.
     */
    public function test_the_trail_still_reads_the_old_vocabulary(): void
    {
        $visit = $this->started();

        $old = VisitStatusHistory::create([
            'visit_id' => $visit->id,
            'hospital_id' => $this->hospital->id,
            'from_status' => 'triage',
            'to_status' => 'consultation',
            'note' => 'Written before the split',
            'changed_by' => null,
            'created_at' => now(),
        ]);

        $this->assertSame('Vitals', $old->fromLabel());
        $this->assertSame('With doctor', $old->toLabel());
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function started(): Visit
    {
        return $this->svc->start($this->svc->open(['patient_id' => $this->patient->id]));
    }

    private function atStage(VisitStage $stage): Visit
    {
        $visit = $this->started();
        $this->svc->overrideState($visit, VisitStatus::Ongoing, $stage, null, null, 'Set up for the test');

        return $visit->fresh();
    }

    private function order(Visit $visit, OrderStatus $status): Order
    {
        $order = app(OrderService::class)->place($visit, OrderType::Procedure, 'Something');
        $order->update(['status' => $status]);

        return $order->fresh();
    }

    private function invoice(Visit $visit, string $total, string $paid): Invoice
    {
        return Invoice::create([
            'uuid' => (string) Str::uuid(),
            'hospital_id' => $this->hospital->id,
            'visit_id' => $visit->id,
            'patient_id' => $this->patient->id,
            'invoice_no' => 'INV-'.$visit->id,
            'currency' => 'UGX',
            'subtotal' => $total, 'tax_total' => '0', 'discount' => '0',
            'total' => $total, 'amount_paid' => $paid,
            'balance' => bcsub($total, $paid, 2),
            'status' => bccomp($paid, '0', 2) > 0 ? InvoiceStatus::PartiallyPaid : InvoiceStatus::Issued,
            'issued_at' => now(),
        ]);
    }
}
