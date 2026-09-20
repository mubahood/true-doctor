<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\GatewayLog;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\Gateway\GatewayPaymentService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GatewayPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        config()->set('services.flutterwave', [
            'secret_key' => 'FLWSECK-test', 'public_key' => 'pk', 'encryption_key' => 'ek',
            'secret_hash' => 'my-hash', 'base_url' => 'https://api.flutterwave.com',
            'currency' => 'UGX', 'payment_options' => 'card', 'timeout' => 20,
        ]);
    }

    private function invoice(Hospital $h, string $price = '100.00')
    {
        app(CurrentHospital::class)->set($h->id);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $c = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $patient->id]);
        $svc = Service::factory()->create(['hospital_id' => $h->id, 'price' => $price]);
        app(BillingService::class)->orderService($c->fresh(), $svc->id, 1);

        return app(BillingService::class)->generateInvoice($c->fresh(), '0.00', null);
    }

    private function fakeVerify(string $txRef, string $amount, string $currency = 'UGX', string $status = 'successful'): void
    {
        Http::fake([
            '*/v3/transactions/*/verify' => Http::response([
                'status' => 'success',
                'data' => ['status' => $status, 'tx_ref' => $txRef, 'id' => 555, 'amount' => (float) $amount, 'currency' => $currency, 'payment_type' => 'mobilemoney'],
            ], 200),
        ]);
    }

    public function test_settle_records_payment_once_and_is_idempotent(): void
    {
        $h = Hospital::factory()->create(['currency' => 'UGX']);
        $invoice = $this->invoice($h);
        $log = GatewayLog::create([
            'hospital_id' => $h->id, 'invoice_id' => $invoice->id, 'provider' => 'flutterwave',
            'tx_ref' => 'TD-abc', 'status' => 'pending', 'amount' => '100.00', 'currency' => 'UGX',
        ]);
        $this->fakeVerify('TD-abc', '100.00');

        $svc = app(GatewayPaymentService::class);
        $this->assertSame('settled', $svc->settle('555'));
        $this->assertSame('already_settled', $svc->settle('555')); // second call = no double credit

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(1, $invoice->payments()->count());
        $this->assertSame('successful', $log->fresh()->status);
        $this->assertSame('flutterwave', $invoice->payments()->first()->method->value);
    }

    public function test_settle_rejects_a_currency_mismatch(): void
    {
        $h = Hospital::factory()->create(['currency' => 'UGX']);
        $invoice = $this->invoice($h);
        GatewayLog::create([
            'hospital_id' => $h->id, 'invoice_id' => $invoice->id, 'provider' => 'flutterwave',
            'tx_ref' => 'TD-cur', 'status' => 'pending', 'amount' => '100.00', 'currency' => 'UGX',
        ]);
        $this->fakeVerify('TD-cur', '100.00', 'USD'); // wrong currency

        $this->assertSame('currency_mismatch', app(GatewayPaymentService::class)->settle('555'));
        $this->assertSame(0, $invoice->payments()->count());
    }

    public function test_settle_rejects_underpayment(): void
    {
        $h = Hospital::factory()->create(['currency' => 'UGX']);
        $invoice = $this->invoice($h);
        GatewayLog::create([
            'hospital_id' => $h->id, 'invoice_id' => $invoice->id, 'provider' => 'flutterwave',
            'tx_ref' => 'TD-short', 'status' => 'pending', 'amount' => '100.00', 'currency' => 'UGX',
        ]);
        $this->fakeVerify('TD-short', '40.00'); // paid less than expected

        $this->assertSame('amount_short', app(GatewayPaymentService::class)->settle('555'));
        $this->assertSame(0, $invoice->payments()->count());
    }

    public function test_webhook_rejects_a_bad_signature(): void
    {
        $res = $this->postJson('/gateway/flutterwave/webhook', ['data' => ['id' => 555]], ['verif-hash' => 'WRONG']);
        $res->assertStatus(401);
    }

    public function test_webhook_with_valid_signature_settles(): void
    {
        $h = Hospital::factory()->create(['currency' => 'UGX']);
        $invoice = $this->invoice($h);
        GatewayLog::create([
            'hospital_id' => $h->id, 'invoice_id' => $invoice->id, 'provider' => 'flutterwave',
            'tx_ref' => 'TD-hook', 'status' => 'pending', 'amount' => '100.00', 'currency' => 'UGX',
        ]);
        $this->fakeVerify('TD-hook', '100.00');

        $this->postJson('/gateway/flutterwave/webhook', ['data' => ['id' => 555]], ['verif-hash' => 'my-hash'])
            ->assertOk();

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_start_redirects_a_staff_member_to_the_payment_link(): void
    {
        $h = Hospital::factory()->create(['currency' => 'UGX']);
        $invoice = $this->invoice($h);
        $user = User::factory()->create(['hospital_id' => $h->id, 'role' => 'receptionist']);
        $user->syncSpatieRole();
        Http::fake(['*/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.com/pay/xyz']], 200)]);

        $this->actingAs($user)->post("/admin/invoices/{$invoice->uuid}/pay/flutterwave")
            ->assertRedirect('https://checkout.flutterwave.com/pay/xyz');
        $this->assertDatabaseHas('gateway_logs', ['invoice_id' => $invoice->id, 'status' => 'pending']);
    }
}
