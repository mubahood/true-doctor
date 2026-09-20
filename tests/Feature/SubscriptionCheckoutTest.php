<?php

namespace Tests\Feature;

use App\Models\GatewayLog;
use App\Models\Hospital;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Subscription checkout, end to end through the real routes (not by reaching
 * into GatewayPaymentService directly) — that's the only way to exercise the
 * contextual binding that makes subscriptions use Pesapal while everything
 * else keeps using Flutterwave (AppServiceProvider).
 */
class SubscriptionCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        config()->set('services.pesapal', [
            'consumer_key' => 'ck-test', 'consumer_secret' => 'cs-test',
            'environment' => 'sandbox', 'sandbox_url' => 'https://cybqa.pesapal.com/pesapalv3',
            'production_url' => 'https://pay.pesapal.com/v3', 'currency' => 'UGX',
            'ipn_url' => 'https://app.test/gateway/pesapal/ipn', 'callback_url' => 'https://app.test/gateway/pesapal/callback',
            'timeout' => 20, 'usd_to_ugx_rate' => 3600.0,
        ]);
    }

    private function owner(Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $u->syncSpatieRole();

        return $u;
    }

    private function fakePesapalAuthAndIpn(): void
    {
        Http::fake([
            '*/api/Auth/RequestToken' => Http::response(['token' => 'tok-123', 'status' => '200'], 200),
            '*/api/URLSetup/RegisterIPN' => Http::response(['ipn_id' => 'ipn-abc', 'status' => '200'], 200),
        ]);
    }

    public function test_checkout_charges_the_months_bought_and_redirects_to_pesapal(): void
    {
        $h = Hospital::factory()->create();
        $plan = Plan::factory()->create(['price' => '10000.00', 'is_active' => true]);
        $this->fakePesapalAuthAndIpn();
        Http::fake(['*/api/Transactions/SubmitOrderRequest' => Http::response([
            'order_tracking_id' => 'ot-1', 'status' => '200',
            'redirect_url' => 'https://pay.pesapal.com/iframe/xyz',
        ], 200)]);

        $this->actingAs($this->owner($h))
            ->post("/admin/subscription/{$plan->id}/checkout", ['phone' => '+256700000000', 'months' => 3])
            ->assertRedirect('https://pay.pesapal.com/iframe/xyz');

        $log = GatewayLog::firstOrFail();
        $this->assertStringStartsWith('SUB'.$h->id.'-', $log->tx_ref);
        $this->assertSame('pesapal', $log->provider);
        $this->assertSame('subscription', $log->meta['purpose']);
        $this->assertSame($plan->id, $log->meta['plan_id']);
        // Quoted in UGX, charged in UGX, three months of it — no conversion anywhere.
        $this->assertSame('UGX', $log->currency);
        $this->assertSame('30000.00', (string) $log->amount);
        $this->assertSame(3, $log->meta['months']);
        $this->assertSame('10000.00', $log->meta['monthly_price']);

        Http::assertSent(fn ($r) => ! str_contains($r->url(), 'SubmitOrderRequest')
            || ((float) $r['amount'] === 30000.0 && $r['billing_address']['phone_number'] === '+256700000000'));
    }

    public function test_checkout_defaults_to_one_month_and_refuses_an_absurd_run(): void
    {
        $h = Hospital::factory()->create();
        $plan = Plan::factory()->create(['price' => '10000.00', 'is_active' => true]);
        $this->fakePesapalAuthAndIpn();
        Http::fake(['*/api/Transactions/SubmitOrderRequest' => Http::response(['status' => '200', 'redirect_url' => 'https://x'], 200)]);

        $this->actingAs($this->owner($h))->post("/admin/subscription/{$plan->id}/checkout");
        $this->assertSame('10000.00', (string) GatewayLog::firstOrFail()->amount);

        $this->actingAs($this->owner($h))
            ->post("/admin/subscription/{$plan->id}/checkout", ['months' => 99])
            ->assertSessionHasErrors('months');
    }

    public function test_checkout_refuses_an_inactive_plan(): void
    {
        $h = Hospital::factory()->create();
        $plan = Plan::factory()->create(['is_active' => false]);

        $this->actingAs($this->owner($h))->post("/admin/subscription/{$plan->id}/checkout")->assertNotFound();
    }

    public function test_settle_activates_the_subscription_for_the_months_paid_for(): void
    {
        $h = Hospital::factory()->create();
        $plan = Plan::factory()->create(['price' => '50000.00', 'billing_cycle' => 'monthly', 'is_active' => true]);
        app(CurrentHospital::class)->set($h->id);
        // A pre-existing trial to convert.
        Subscription::factory()->create(['hospital_id' => $h->id, 'plan_id' => $plan->id, 'status' => 'trialing']);

        GatewayLog::create([
            'hospital_id' => $h->id, 'provider' => 'pesapal', 'tx_ref' => 'SUB'.$h->id.'-xyz',
            'status' => 'pending', 'amount' => '150000.00', 'currency' => 'UGX',
            'meta' => ['purpose' => 'subscription', 'plan_id' => $plan->id, 'months' => 3, 'monthly_price' => '50000.00'],
        ]);

        Http::fake([
            '*/api/Auth/RequestToken' => Http::response(['token' => 'tok-123', 'status' => '200'], 200),
            '*/api/Transactions/GetTransactionStatus*' => Http::response([
                'status_code' => 1, 'payment_status_description' => 'COMPLETED',
                'merchant_reference' => 'SUB'.$h->id.'-xyz', 'amount' => 150000.0, 'currency' => 'UGX', 'payment_method' => 'MPESA',
            ], 200),
        ]);

        $this->get('/gateway/pesapal/callback?OrderTrackingId=ot-900')->assertOk()->assertSee('Payment received');

        $sub = Subscription::where('hospital_id', $h->id)->latest('starts_at')->first();
        $this->assertSame('active', $sub->status->value);
        $this->assertNull($sub->trial_ends_at);
        // Three months bought, three months granted (± a moment for the clock).
        $this->assertEqualsWithDelta(3, now()->diffInMonths($sub->ends_at), 0.05);
        $payment = $sub->payments()->first();
        $this->assertSame('pesapal', $payment->method);
        $this->assertSame('150000.00', (string) $payment->amount);
        $this->assertSame('3 months', $payment->notes);
    }

    /** Renewing early must not throw away time already paid for. */
    public function test_renewing_before_expiry_adds_to_the_end_of_the_current_period(): void
    {
        $h = Hospital::factory()->create();
        $plan = Plan::factory()->create(['price' => '10000.00', 'is_active' => true]);
        app(CurrentHospital::class)->set($h->id);
        Subscription::factory()->create([
            'hospital_id' => $h->id, 'plan_id' => $plan->id, 'status' => 'active',
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(),
        ]);

        GatewayLog::create([
            'hospital_id' => $h->id, 'provider' => 'pesapal', 'tx_ref' => 'SUB'.$h->id.'-early',
            'status' => 'pending', 'amount' => '20000.00', 'currency' => 'UGX',
            'meta' => ['purpose' => 'subscription', 'plan_id' => $plan->id, 'months' => 2, 'monthly_price' => '10000.00'],
        ]);

        Http::fake([
            '*/api/Auth/RequestToken' => Http::response(['token' => 'tok-123', 'status' => '200'], 200),
            '*/api/Transactions/GetTransactionStatus*' => Http::response([
                'status_code' => 1, 'merchant_reference' => 'SUB'.$h->id.'-early', 'amount' => 20000.0, 'currency' => 'UGX',
            ], 200),
        ]);

        $this->get('/gateway/pesapal/callback?OrderTrackingId=ot-early')->assertOk();

        // One month still owed + two bought = three months out, not two.
        $sub = Subscription::where('hospital_id', $h->id)->first();
        $this->assertEqualsWithDelta(3, now()->diffInMonths($sub->ends_at), 0.05);
    }

    public function test_settling_twice_never_double_activates(): void
    {
        $h = Hospital::factory()->create();
        $plan = Plan::factory()->create(['price' => '10000.00', 'is_active' => true]);
        app(CurrentHospital::class)->set($h->id);

        GatewayLog::create([
            'hospital_id' => $h->id, 'provider' => 'pesapal', 'tx_ref' => 'SUB'.$h->id.'-once',
            'status' => 'pending', 'amount' => '10000.00', 'currency' => 'UGX',
            'meta' => ['purpose' => 'subscription', 'plan_id' => $plan->id, 'months' => 1, 'monthly_price' => '10000.00'],
        ]);

        Http::fake([
            '*/api/Auth/RequestToken' => Http::response(['token' => 'tok-123', 'status' => '200'], 200),
            '*/api/Transactions/GetTransactionStatus*' => Http::response([
                'status_code' => 1, 'payment_status_description' => 'COMPLETED',
                'merchant_reference' => 'SUB'.$h->id.'-once', 'amount' => 10000.0, 'currency' => 'UGX',
            ], 200),
        ]);

        $this->get('/gateway/pesapal/callback?OrderTrackingId=ot-1')->assertOk();
        $this->get('/gateway/pesapal/callback?OrderTrackingId=ot-1')->assertOk();

        $this->assertSame(1, Subscription::where('hospital_id', $h->id)->first()->payments()->count());
    }

    public function test_the_ipn_endpoint_acknowledges_in_the_shape_pesapal_expects(): void
    {
        $h = Hospital::factory()->create();
        $plan = Plan::factory()->create(['price' => '10000.00', 'is_active' => true]);
        app(CurrentHospital::class)->set($h->id);

        GatewayLog::create([
            'hospital_id' => $h->id, 'provider' => 'pesapal', 'tx_ref' => 'SUB'.$h->id.'-ipn',
            'status' => 'pending', 'amount' => '10000.00', 'currency' => 'UGX',
            'meta' => ['purpose' => 'subscription', 'plan_id' => $plan->id, 'months' => 1, 'monthly_price' => '10000.00'],
        ]);

        Http::fake([
            '*/api/Auth/RequestToken' => Http::response(['token' => 'tok-123', 'status' => '200'], 200),
            '*/api/Transactions/GetTransactionStatus*' => Http::response([
                'status_code' => 1, 'payment_status_description' => 'COMPLETED',
                'merchant_reference' => 'SUB'.$h->id.'-ipn', 'amount' => 10000.0, 'currency' => 'UGX',
            ], 200),
        ]);

        $this->get('/gateway/pesapal/ipn?OrderTrackingId=ot-2&OrderMerchantReference=SUB'.$h->id.'-ipn&OrderNotificationType=IPNCHANGE')
            ->assertOk()
            ->assertExactJson([
                'orderNotificationType' => 'IPNCHANGE',
                'orderTrackingId' => 'ot-2',
                'orderMerchantReference' => 'SUB'.$h->id.'-ipn',
                'status' => 200,
            ]);
    }

    public function test_the_ipn_endpoint_reports_500_for_an_unrecognised_reference(): void
    {
        // A genuinely completed payment, but for a tx_ref no GatewayLog row was ever opened for
        // (e.g. our own row failed to insert) — a problem on our side, so Pesapal should retry.
        Http::fake([
            '*/api/Auth/RequestToken' => Http::response(['token' => 'tok-123', 'status' => '200'], 200),
            '*/api/Transactions/GetTransactionStatus*' => Http::response([
                'status_code' => 1, 'payment_status_description' => 'COMPLETED',
                'merchant_reference' => 'NOPE', 'amount' => 1, 'currency' => 'UGX',
            ], 200),
        ]);

        $this->get('/gateway/pesapal/ipn?OrderTrackingId=ot-unknown&OrderMerchantReference=NOPE')
            ->assertOk()
            ->assertJsonPath('status', 500);
    }
}
