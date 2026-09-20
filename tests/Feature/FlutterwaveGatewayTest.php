<?php

namespace Tests\Feature;

use App\Services\Gateway\FlutterwaveGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The Flutterwave adapter in isolation — request shape + response parsing, no real API. */
class FlutterwaveGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.flutterwave', [
            'secret_key' => 'FLWSECK-test', 'public_key' => 'pk', 'encryption_key' => 'ek',
            'secret_hash' => 'my-hash', 'base_url' => 'https://api.flutterwave.com',
            'currency' => 'UGX', 'payment_options' => 'card', 'timeout' => 20,
        ]);
    }

    public function test_initialize_returns_the_payment_link(): void
    {
        Http::fake(['*/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.com/pay/abc']], 200)]);

        $charge = app(FlutterwaveGateway::class)->initialize([
            'tx_ref' => 'TD-x', 'amount' => '100.00', 'currency' => 'UGX',
            'redirect_url' => 'https://app.test/cb', 'customer' => ['email' => 'a@b.c'],
        ]);

        $this->assertTrue($charge->ok);
        $this->assertSame('https://checkout.flutterwave.com/pay/abc', $charge->link);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer FLWSECK-test') && $r['tx_ref'] === 'TD-x');
    }

    public function test_verify_normalizes_a_successful_transaction(): void
    {
        Http::fake(['*/v3/transactions/99/verify' => Http::response([
            'status' => 'success',
            'data' => ['status' => 'successful', 'tx_ref' => 'TD-x', 'id' => 99, 'amount' => 100, 'currency' => 'UGX', 'payment_type' => 'mobilemoney'],
        ], 200)]);

        $v = app(FlutterwaveGateway::class)->verify('99');

        $this->assertTrue($v->successful);
        $this->assertSame('TD-x', $v->reference);
        $this->assertSame('99', $v->providerRef);
        $this->assertSame('100.00', $v->amount);
        $this->assertSame('UGX', $v->currency);
    }

    public function test_verify_flags_a_failed_transaction(): void
    {
        Http::fake(['*/v3/transactions/1/verify' => Http::response(['status' => 'success', 'data' => ['status' => 'failed', 'tx_ref' => 'TD-y', 'amount' => 0, 'currency' => 'UGX']], 200)]);

        $this->assertFalse(app(FlutterwaveGateway::class)->verify('1')->successful);
    }

    public function test_webhook_signature_is_constant_time_checked(): void
    {
        $gw = app(FlutterwaveGateway::class);
        $this->assertTrue($gw->verifyWebhookSignature('my-hash'));
        $this->assertFalse($gw->verifyWebhookSignature('wrong'));
        $this->assertFalse($gw->verifyWebhookSignature(null));
    }
}
