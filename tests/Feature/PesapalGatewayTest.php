<?php

namespace Tests\Feature;

use App\Services\Gateway\PesapalGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The Pesapal adapter in isolation — auth/IPN caching, request shape, response parsing, no real API. */
class PesapalGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.pesapal', [
            'consumer_key' => 'ck-test', 'consumer_secret' => 'cs-test',
            'environment' => 'sandbox', 'sandbox_url' => 'https://cybqa.pesapal.com/pesapalv3',
            'production_url' => 'https://pay.pesapal.com/v3', 'currency' => 'UGX',
            'ipn_url' => 'https://app.test/gateway/pesapal/ipn', 'callback_url' => 'https://app.test/gateway/pesapal/callback',
            'timeout' => 20, 'usd_to_ugx_rate' => 3600.0,
        ]);
    }

    public function test_initialize_returns_the_redirect_url(): void
    {
        Http::fake([
            '*/api/Auth/RequestToken' => Http::response(['token' => 'tok-123', 'status' => '200'], 200),
            '*/api/URLSetup/RegisterIPN' => Http::response(['ipn_id' => 'ipn-abc', 'status' => '200'], 200),
            '*/api/Transactions/SubmitOrderRequest' => Http::response([
                'order_tracking_id' => 'ot-1', 'merchant_reference' => 'SUB1-abc', 'status' => '200',
                'redirect_url' => 'https://pay.pesapal.com/iframe/xyz',
            ], 200),
        ]);

        $charge = app(PesapalGateway::class)->initialize([
            'tx_ref' => 'SUB1-abc', 'amount' => '176400.00', 'currency' => 'UGX',
            'redirect_url' => 'https://app.test/cb', 'customer' => ['email' => 'a@b.c', 'phone' => '+256700000000', 'name' => 'Jane Doe'],
            'meta' => ['description' => 'Pro plan'],
        ]);

        $this->assertTrue($charge->ok);
        $this->assertSame('https://pay.pesapal.com/iframe/xyz', $charge->link);

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), 'SubmitOrderRequest')) {
                return true;
            }

            return $r->hasHeader('Authorization', 'Bearer tok-123')
                && $r['id'] === 'SUB1-abc'
                && $r['currency'] === 'UGX'
                && (float) $r['amount'] === 176400.0
                && $r['notification_id'] === 'ipn-abc'
                && $r['billing_address']['email_address'] === 'a@b.c'
                && $r['billing_address']['phone_number'] === '+256700000000'
                && $r['billing_address']['first_name'] === 'Jane'
                && $r['billing_address']['last_name'] === 'Doe';
        });
    }

    public function test_the_token_and_the_ipn_id_are_each_fetched_once_and_reused(): void
    {
        Http::fake([
            '*/api/Auth/RequestToken' => Http::response(['token' => 'tok-123', 'status' => '200'], 200),
            '*/api/URLSetup/RegisterIPN' => Http::response(['ipn_id' => 'ipn-abc', 'status' => '200'], 200),
            '*/api/Transactions/SubmitOrderRequest' => Http::response(['status' => '200', 'redirect_url' => 'https://x'], 200),
        ]);

        $gateway = app(PesapalGateway::class);
        $gateway->initialize(['tx_ref' => 'A', 'amount' => '1', 'currency' => 'UGX', 'redirect_url' => 'https://x', 'customer' => ['email' => 'a@b.c']]);
        $gateway->initialize(['tx_ref' => 'B', 'amount' => '1', 'currency' => 'UGX', 'redirect_url' => 'https://x', 'customer' => ['email' => 'a@b.c']]);

        // Two orders submitted, but the token and the IPN id are each fetched once and reused.
        $this->assertCount(1, Http::recorded(fn ($request) => str_contains((string) $request->url(), 'RequestToken')));
        $this->assertCount(1, Http::recorded(fn ($request) => str_contains((string) $request->url(), 'RegisterIPN')));
        $this->assertCount(2, Http::recorded(fn ($request) => str_contains((string) $request->url(), 'SubmitOrderRequest')));
    }

    public function test_verify_normalizes_a_completed_transaction(): void
    {
        Http::fake([
            '*/api/Auth/RequestToken' => Http::response(['token' => 'tok-123', 'status' => '200'], 200),
            '*/api/Transactions/GetTransactionStatus*' => Http::response([
                'payment_method' => 'MPESA', 'amount' => 176400, 'status_code' => 1,
                'payment_status_description' => 'COMPLETED', 'merchant_reference' => 'SUB1-abc', 'currency' => 'UGX',
            ], 200),
        ]);

        $v = app(PesapalGateway::class)->verify('ot-1');

        $this->assertTrue($v->successful);
        $this->assertSame('SUB1-abc', $v->reference);
        $this->assertSame('ot-1', $v->providerRef);
        $this->assertSame('176400.00', $v->amount);
        $this->assertSame('UGX', $v->currency);
        $this->assertSame('MPESA', $v->paymentType);

        Http::assertSent(fn ($r) => ! str_contains($r->url(), 'GetTransactionStatus') || $r['orderTrackingId'] === 'ot-1');
    }

    public function test_verify_flags_a_failed_transaction(): void
    {
        Http::fake([
            '*/api/Auth/RequestToken' => Http::response(['token' => 'tok-123', 'status' => '200'], 200),
            '*/api/Transactions/GetTransactionStatus*' => Http::response([
                'status_code' => 2, 'payment_status_description' => 'FAILED', 'merchant_reference' => 'SUB1-abc', 'currency' => 'UGX', 'amount' => 0,
            ], 200),
        ]);

        $this->assertFalse(app(PesapalGateway::class)->verify('ot-1')->successful);
    }

    public function test_initialize_fails_gracefully_when_auth_is_rejected(): void
    {
        Http::fake(['*/api/Auth/RequestToken' => Http::response(['error' => 'invalid credentials'], 401)]);

        $charge = app(PesapalGateway::class)->initialize([
            'tx_ref' => 'X', 'amount' => '1', 'currency' => 'UGX', 'redirect_url' => 'https://x', 'customer' => ['email' => 'a@b.c'],
        ]);

        $this->assertFalse($charge->ok);
    }

    /** Pesapal's IPN carries no signature — the real check is verify()'s own authenticated call. */
    public function test_webhook_signature_check_is_always_true(): void
    {
        $gw = app(PesapalGateway::class);
        $this->assertTrue($gw->verifyWebhookSignature(null));
        $this->assertTrue($gw->verifyWebhookSignature('anything'));
    }
}
