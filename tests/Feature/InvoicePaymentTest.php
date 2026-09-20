<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\OverpaymentException;
use App\Models\Hospital;
use App\Models\Service;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\CardService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Money-critical: every invoice roll-up and every payment path is validated at
 * the service level here (HMS_PLAN.md — "unit tests for every calculation").
 */
class InvoicePaymentTest extends TestCase
{
    use RefreshDatabase;

    private BillingService $billing;

    private CardService $cards;

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        $this->billing = app(BillingService::class);
        $this->cards = app(CardService::class);
    }

    private function boot(array $billing = []): Hospital
    {
        $this->hospital = Hospital::factory()->create([
            'currency' => $billing['currency_code'] ?? 'USD',
            'settings' => ['billing' => $billing],
        ]);
        app(CurrentHospital::class)->set($this->hospital->id);

        return $this->hospital;
    }

    private function visitWithCharges(array $prices, array $billing = []): Visit
    {
        $this->boot($billing);
        $patient = \App\Models\Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $c = Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $patient->id]);
        foreach ($prices as $p) {
            $svc = Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => $p]);
            $this->billing->orderService($c, $svc->id, 1);
        }

        return $c->fresh();
    }

    public function test_invoice_rolls_up_subtotal_tax_and_total(): void
    {
        $c = $this->visitWithCharges(['100.00', '50.00'], ['tax_enabled' => true, 'tax_rate' => 10]);

        $invoice = $this->billing->generateInvoice($c, '0.00', null);

        $this->assertSame('150.00', (string) $invoice->subtotal);
        $this->assertSame('15.00', (string) $invoice->tax_total);
        $this->assertSame('165.00', (string) $invoice->total);
        $this->assertSame('165.00', (string) $invoice->balance);
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame(2, $invoice->items()->count());
        $this->assertMatchesRegularExpression('/^INV-\d{4}-\d{5}$/', $invoice->invoice_no);
    }

    public function test_discount_reduces_the_total(): void
    {
        $c = $this->visitWithCharges(['200.00']);

        $invoice = $this->billing->generateInvoice($c, '30.00', null);

        $this->assertSame('200.00', (string) $invoice->subtotal);
        $this->assertSame('30.00', (string) $invoice->discount);
        $this->assertSame('170.00', (string) $invoice->total);
    }

    public function test_discount_cannot_exceed_the_amount(): void
    {
        $c = $this->visitWithCharges(['50.00']);

        $this->expectException(\RuntimeException::class);
        $this->billing->generateInvoice($c, '80.00', null);
    }

    public function test_one_live_invoice_per_visit(): void
    {
        $c = $this->visitWithCharges(['40.00']);
        $this->billing->generateInvoice($c, '0.00', null);

        $this->expectException(\RuntimeException::class);
        $this->billing->generateInvoice($c, '0.00', null);
    }

    public function test_cash_partial_then_full_payment_tracks_balance_and_status(): void
    {
        $c = $this->visitWithCharges(['100.00']);
        $invoice = $this->billing->generateInvoice($c, '0.00', null);

        $this->billing->recordPayment($invoice, PaymentMethod::Cash, '40.00', [], null);
        $invoice->refresh();
        $this->assertSame('40.00', (string) $invoice->amount_paid);
        $this->assertSame('60.00', (string) $invoice->balance);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);

        $this->billing->recordPayment($invoice, PaymentMethod::Cash, '60.00', [], null);
        $invoice->refresh();
        $this->assertSame('0.00', (string) $invoice->balance);
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(2, $invoice->payments()->count());
    }

    public function test_overpayment_is_rejected(): void
    {
        $c = $this->visitWithCharges(['100.00']);
        $invoice = $this->billing->generateInvoice($c, '0.00', null);

        $this->expectException(OverpaymentException::class);
        $this->billing->recordPayment($invoice, PaymentMethod::Cash, '150.00', [], null);
    }

    public function test_paid_invoice_rejects_further_payment(): void
    {
        $c = $this->visitWithCharges(['20.00']);
        $invoice = $this->billing->generateInvoice($c, '0.00', null);
        $this->billing->recordPayment($invoice, PaymentMethod::Cash, '20.00', [], null);

        $this->expectException(\RuntimeException::class);
        $this->billing->recordPayment($invoice->fresh(), PaymentMethod::Cash, '1.00', [], null);
    }

    public function test_card_payment_debits_the_prepaid_card_ledger(): void
    {
        $c = $this->visitWithCharges(['80.00']);
        $invoice = $this->billing->generateInvoice($c, '0.00', null);
        $card = $this->cards->issue($c->patient, []);
        $this->cards->credit($card, '100.00', 'Top-up');

        $payment = $this->billing->recordPayment($invoice, PaymentMethod::Card, '80.00', ['card' => $card], null);

        $this->assertSame('20.00', (string) $card->fresh()->balance);           // 100 - 80
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertNotNull($payment->card_record_id);                          // ledger link
        $this->assertSame($card->id, $payment->patient_card_id);
    }

    public function test_card_payment_without_funds_rolls_back_everything(): void
    {
        $c = $this->visitWithCharges(['80.00']);
        $invoice = $this->billing->generateInvoice($c, '0.00', null);
        $card = $this->cards->issue($c->patient, []); // balance 0, no credit

        try {
            $this->billing->recordPayment($invoice, PaymentMethod::Card, '80.00', ['card' => $card], null);
            $this->fail('Expected InsufficientFundsException');
        } catch (InsufficientFundsException $e) {
            // expected
        }

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);   // unchanged
        $this->assertSame('80.00', (string) $invoice->balance);
        $this->assertSame(0, $invoice->payments()->count());          // no orphan payment
        $this->assertSame('0.00', (string) $card->fresh()->balance);
    }
}
