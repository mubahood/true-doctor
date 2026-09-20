<?php

namespace Tests\Feature\Livewire;

use App\Enums\CardStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\VisitStage;
use App\Enums\VisitStatus;
use App\Livewire\Visits\Panels\Payments;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientCard;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\CardService;
use App\Services\OrderService;
use App\Services\VisitService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Taking money against a visit's invoice.
 *
 * The last link in the chain: a visit has orders, an order has items, the
 * items are the bill, and this settles it. Each method needs something
 * different and the panel asks for exactly that — all of it was supported by
 * BillingService and none of it reachable, because the panel passed an empty
 * options array.
 */
class PaymentsPanelTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private Visit $visit;

    private Patient $patient;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->admin = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin']);
        $this->admin->syncSpatieRole();
        $this->actingAs($this->admin);

        $this->patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $this->visit = app(VisitService::class)->open(['patient_id' => $this->patient->id]);
    }

    private function panel(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Payments::class, ['visitId' => $this->visit->id, 'lazy' => false]);
    }

    /** A bill of $amount, invoiced and ready to be paid against. */
    private function invoiced(string $amount = '10000'): Invoice
    {
        $order = app(OrderService::class)->place($this->visit, OrderType::Lab, 'Bloods', [], $this->admin->id);
        $service = Service::factory()->create([
            'hospital_id' => $this->hospital->id, 'name' => 'Care', 'price' => $amount, 'is_active' => true,
        ]);
        app(BillingService::class)->addServiceLine($order, $service, 1, $this->admin->id);

        return app(BillingService::class)->generateInvoice($this->visit->fresh(), '0.00', $this->admin->id);
    }

    private function card(string $balance = '50000', CardStatus $status = CardStatus::Active): PatientCard
    {
        $card = app(CardService::class)->issue($this->patient, [], $this->admin->id);

        if (bccomp($balance, '0', 2) > 0) {
            app(CardService::class)->credit($card, $balance, 'Top-up', $this->admin->id);
        }

        $card->update(['status' => $status]);

        return $card->fresh();
    }

    // ── Nothing to pay until there is an invoice ─────────────────────────

    public function test_there_is_nothing_to_pay_before_the_bill_is_invoiced(): void
    {
        // Asserted on the control, not the words: the dialog's own title
        // renders on every pass whether or not it is open.
        $this->panel()
            ->assertSee('the bill has not been invoiced')
            ->assertDontSee('wire:click="openTake"', escape: false);
    }

    public function test_opening_the_form_without_an_invoice_is_refused(): void
    {
        $this->panel()->call('openTake')->assertForbidden();
    }

    // ── The methods offered ──────────────────────────────────────────────

    /**
     * A gateway settles itself.
     *
     * Offering Flutterwave at the counter would let somebody mark an invoice
     * paid with money nobody has confirmed arrived.
     */
    public function test_a_gateway_method_is_never_offered_at_the_counter(): void
    {
        $this->invoiced();

        $methods = array_column($this->panel()->get('methods'), 'value');

        $this->assertContains(PaymentMethod::Cash->value, $methods);
        $this->assertContains(PaymentMethod::MobileMoney->value, $methods);
        $this->assertNotContains(PaymentMethod::Flutterwave->value, $methods);
    }

    /** And it is refused even when the request is forged. */
    public function test_a_gateway_payment_cannot_be_recorded_by_hand(): void
    {
        $invoice = $this->invoiced();

        $this->panel()
            ->call('openTake')
            ->set('method', PaymentMethod::Flutterwave->value)
            ->set('amount', '10000')
            ->call('take')
            ->assertHasErrors('method');

        $this->assertSame('10000.00', (string) $invoice->fresh()->balance);
    }

    // ── Cash ─────────────────────────────────────────────────────────────

    public function test_the_form_opens_on_the_full_balance(): void
    {
        $this->invoiced('10000');

        $this->panel()->call('openTake')->assertSet('amount', '10000.00')->assertSet('method', 'cash');
    }

    public function test_a_cash_payment_settles_the_invoice_and_closes_the_visit(): void
    {
        $invoice = $this->invoiced('10000');
        app(VisitService::class)->overrideState(
            $this->visit, VisitStatus::Ongoing, VisitStage::Payment, null, $this->admin->id, 'Set up',
        );

        $this->panel()
            ->call('openTake')
            ->set('amount', '10000')
            ->call('take')
            ->assertHasNoErrors()
            ->assertSet('showTake', false)
            ->assertDispatched('visit-updated');

        $this->assertSame('0.00', (string) $invoice->fresh()->balance);
        $this->assertSame(VisitStage::Completed, $this->visit->fresh()->stage, 'a settled visit did not close itself');
    }

    /** A part payment is fine, and says what is left. */
    public function test_a_part_payment_leaves_a_balance(): void
    {
        $invoice = $this->invoiced('10000');

        $this->panel()->call('openTake')->set('amount', '4000')->call('take')->assertHasNoErrors();

        $this->assertSame('6000.00', (string) $invoice->fresh()->balance);
        $this->panel()->assertSee('6,000');
    }

    public function test_paying_more_than_the_balance_is_refused(): void
    {
        $invoice = $this->invoiced('10000');

        $this->panel()->call('openTake')->set('amount', '15000')->call('take')->assertHasErrors('amount');

        $this->assertSame('10000.00', (string) $invoice->fresh()->balance);
    }

    public function test_full_balance_fills_the_amount(): void
    {
        $invoice = $this->invoiced('10000');
        app(BillingService::class)->recordPayment($invoice, PaymentMethod::Cash, '2500', [], $this->admin->id);

        $this->panel()->call('openTake')->set('amount', '1')->call('payInFull')->assertSet('amount', '7500.00');
    }

    // ── A reference is what proves the payment later ─────────────────────

    /**
     * Cash is its own receipt; a transfer is somebody else's record.
     *
     * Without the reference there is no way back to it when the figures are
     * questioned — and the panel could not record one at all before.
     */
    public function test_mobile_money_and_bank_and_insurance_need_a_reference(): void
    {
        $this->invoiced();

        foreach ([PaymentMethod::MobileMoney, PaymentMethod::Bank, PaymentMethod::Insurance] as $method) {
            $this->panel()
                ->call('openTake')
                ->set('method', $method->value)
                ->set('amount', '1000')
                ->set('reference', '')
                ->call('take')
                ->assertHasErrors('reference');
        }

        $this->panel()->call('openTake')->set('amount', '1000')->call('take')->assertHasNoErrors();
    }

    public function test_the_reference_is_stored_and_shown(): void
    {
        $this->invoiced();

        $this->panel()
            ->call('openTake')
            ->set('method', PaymentMethod::MobileMoney->value)
            ->set('amount', '10000')
            ->set('reference', 'MPX7Y2K9QL')
            ->call('take')
            ->assertHasNoErrors();

        $payment = \App\Models\Payment::firstOrFail();
        $this->assertSame('MPX7Y2K9QL', $payment->reference);

        $this->panel()->assertSee('MPX7Y2K9QL');
    }

    // ── The prepaid card ─────────────────────────────────────────────────

    /** This was impossible before: the panel never passed the card through. */
    public function test_a_card_payment_debits_the_card(): void
    {
        $invoice = $this->invoiced('10000');
        $card = $this->card('50000');

        $this->panel()
            ->call('openTake')
            ->set('method', PaymentMethod::Card->value)
            ->set('patient_card_id', $card->id)
            ->set('amount', '10000')
            ->call('take')
            ->assertHasNoErrors();

        $this->assertSame('0.00', (string) $invoice->fresh()->balance);
        $this->assertSame('40000.00', (string) $card->fresh()->balance, 'the card was never debited');

        $payment = \App\Models\Payment::firstOrFail();
        $this->assertSame($card->id, $payment->patient_card_id);
        $this->assertNotNull($payment->card_record_id, 'no entry was written to the card ledger');
    }

    public function test_a_card_payment_needs_a_card(): void
    {
        $this->invoiced();

        $this->panel()
            ->call('openTake')
            ->set('method', PaymentMethod::Card->value)
            ->set('amount', '10000')
            ->call('take')
            ->assertHasErrors('patient_card_id');
    }

    /** A card with nothing on it says so, and nothing is recorded. */
    public function test_a_card_without_the_funds_is_refused(): void
    {
        $invoice = $this->invoiced('10000');
        $card = $this->card('2000');

        $this->panel()
            ->call('openTake')
            ->set('method', PaymentMethod::Card->value)
            ->set('patient_card_id', $card->id)
            ->set('amount', '10000')
            ->call('take')
            ->assertHasErrors('amount');

        $this->assertSame('10000.00', (string) $invoice->fresh()->balance);
        $this->assertSame('2000.00', (string) $card->fresh()->balance, 'the card was debited anyway');
    }

    /** Another patient's card is not this invoice's to spend. */
    public function test_another_patients_card_cannot_be_used(): void
    {
        $invoice = $this->invoiced('10000');

        $someoneElse = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $theirs = app(CardService::class)->issue($someoneElse, [], $this->admin->id);
        app(CardService::class)->credit($theirs, '50000', 'Top-up', $this->admin->id);

        $this->panel()
            ->call('openTake')
            ->set('method', PaymentMethod::Card->value)
            ->set('patient_card_id', $theirs->id)
            ->set('amount', '10000')
            ->call('take')
            ->assertHasErrors();

        $this->assertSame('10000.00', (string) $invoice->fresh()->balance);
        $this->assertSame('50000.00', (string) $theirs->fresh()->balance);
    }

    /** Only this patient's cards are offered at all. */
    public function test_only_this_patients_cards_are_offered(): void
    {
        $this->invoiced();
        $mine = $this->card('50000');

        $someoneElse = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        app(CardService::class)->issue($someoneElse, [], $this->admin->id);

        $cards = $this->panel()->call('openTake')->get('cards');

        $this->assertSame([$mine->id], $cards->pluck('id')->all());
    }

    /** Choosing the card method reaches for a usable card by itself. */
    public function test_choosing_card_picks_a_usable_one(): void
    {
        $this->invoiced();
        $card = $this->card('50000');

        $this->panel()
            ->call('openTake')
            ->set('method', PaymentMethod::Card->value)
            ->assertSet('patient_card_id', $card->id);
    }

    // ── The receipt ──────────────────────────────────────────────────────

    public function test_every_payment_offers_its_receipt(): void
    {
        $invoice = $this->invoiced('10000');
        $payment = app(BillingService::class)->recordPayment(
            $invoice, PaymentMethod::Cash, '10000', [], $this->admin->id,
        );

        $this->panel()->assertSee(route('admin.payments.receipt', $payment), escape: false);

        $this->get(route('admin.payments.receipt', $payment))->assertOk();
    }

    // ── Who may ──────────────────────────────────────────────────────────

    public function test_a_billing_reader_may_look_but_not_take(): void
    {
        $this->invoiced();

        $reader = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $reader->syncSpatieRole();
        $reader->givePermissionTo('billing.view');
        $this->actingAs($reader);

        $this->panel()->assertDontSee('wire:click="openTake"', escape: false);
        $this->panel()->call('openTake')->assertForbidden();
        $this->panel()->call('take')->assertForbidden();
    }
}
