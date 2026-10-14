<?php

namespace Tests\Feature;

use App\Models\StandaloneLicence;
use App\Models\StandaloneOrder;
use App\Models\StandaloneProduct;
use App\Models\StandaloneRelease;
use App\Models\User;
use App\Services\Gateway\GatewayCharge;
use App\Services\Gateway\GatewayVerification;
use App\Services\Gateway\PesapalGateway;
use App\Services\StandaloneSalesService;
use App\Support\Licensing\Verifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StandaloneSaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        config()->set('edition.name', 'saas');
        config()->set('edition.signing_key_id', 'sale-test');
        config()->set('edition.signing_secret', base64_encode(sodium_crypto_sign_secretkey($pair)));
        config()->set('edition.public_keys', ['sale-test' => base64_encode(sodium_crypto_sign_publickey($pair))]);
        Mail::fake();
    }

    public function test_verified_payment_issues_one_licence_and_snapshots_the_checkout_price(): void
    {
        StandaloneRelease::create(['version' => '1.0.0', 'status' => 'published', 'published_at' => now()]);
        $product = StandaloneProduct::create([
            'name' => 'Hospital', 'slug' => 'hospital', 'price_ugx' => '1500000.00',
            'updates_months' => 12, 'is_active' => true, 'limits' => ['max_staff' => 50],
        ]);
        $gateway = \Mockery::mock(PesapalGateway::class);
        $gateway->shouldReceive('initialize')->once()->andReturnUsing(
            static fn (array $data): GatewayCharge => new GatewayCharge(true, $data['tx_ref'], 'https://payments.example.test/pay'),
        );
        app()->instance(PesapalGateway::class, $gateway);
        $sales = app(StandaloneSalesService::class);
        [$order, $charge] = $sales->start($product, 'Buyer Hospital', 'owner@example.test', 'hospital.example.test');
        $this->assertTrue($charge->ok);
        $product->update(['price_ugx' => '2000000.00', 'updates_months' => 6]);
        $this->assertSame('1500000.00', $order->fresh()->amount_ugx);

        $payment = new GatewayVerification(true, $order->reference, 'tracking-123', '1500000.00', 'UGX');
        $this->assertSame('settled', $sales->settleVerified($payment));
        $this->assertSame('already_settled', $sales->settleVerified($payment));
        $this->assertSame(1, StandaloneLicence::count());
        $this->assertSame(1, StandaloneOrder::where('status', 'paid')->count());
        $licence = StandaloneLicence::firstOrFail();
        $this->assertSame('hospital.example.test', $licence->domains[0]);
        $this->assertSame(50, $licence->limits['max_staff']);
        $this->assertTrue(app(Verifier::class)->verifiesDocument($sales->document($licence)));
    }

    public function test_underpayment_cannot_issue_a_licence(): void
    {
        $product = StandaloneProduct::create([
            'name' => 'Hospital', 'slug' => 'hospital', 'price_ugx' => '1500000.00',
            'updates_months' => 12, 'is_active' => true,
        ]);
        $order = StandaloneOrder::create([
            'product_id' => $product->id, 'reference' => 'TDL-underpay',
            'customer_name' => 'Buyer', 'customer_email' => 'buyer@example.test',
            'domain' => 'hospital.example.test', 'amount_ugx' => '1500000.00',
            'updates_months' => 12, 'status' => 'pending',
        ]);
        $result = app(StandaloneSalesService::class)->settleVerified(
            new GatewayVerification(true, $order->reference, 'tracking-small', '1499999.00', 'UGX'),
        );
        $this->assertSame('amount_or_currency_mismatch', $result);
        $this->assertSame(0, StandaloneLicence::count());
    }

    public function test_standalone_routes_are_absent_in_a_customer_installation(): void
    {
        config()->set('edition.name', 'standalone');
        $marker = tempnam(sys_get_temp_dir(), 'td-sale-marker-');
        file_put_contents($marker, '{"edition":"standalone"}');
        config()->set('edition.installed_path', $marker);
        try {
            $this->get('/standalone')->assertNotFound();
            $this->get('/my-licence')->assertNotFound();
            $this->get('/super/products')->assertNotFound();
            $this->get('/super/licences')->assertNotFound();
            $this->get('/super/releases')->assertNotFound();
        } finally {
            unlink($marker);
        }
    }

    public function test_super_admin_can_open_release_product_and_licence_controls(): void
    {
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $super = User::factory()->create(['role' => 'super_admin', 'hospital_id' => null]);
        $this->actingAs($super)->get('/super/releases')->assertOk();
        $this->actingAs($super)->get('/super/products')->assertOk();
        $this->actingAs($super)->get('/super/licences')->assertOk();
    }

    public function test_confirmed_checkout_opens_the_buyer_portal_immediately(): void
    {
        $archive = tempnam(sys_get_temp_dir(), 'td-sale-zip-');
        file_put_contents($archive, 'fixture release archive');
        try {
            $release = StandaloneRelease::create([
                'version' => '1.0.0', 'status' => 'published', 'published_at' => now(),
                'archive_path' => $archive, 'sha256' => hash_file('sha256', $archive),
            ]);
            $product = StandaloneProduct::create([
                'name' => 'Hospital', 'slug' => 'hospital', 'price_ugx' => '1500000.00',
                'updates_months' => 12, 'is_active' => true,
            ]);
            $gateway = \Mockery::mock(PesapalGateway::class);
            $gateway->shouldReceive('initialize')->once()->andReturnUsing(
                static fn (array $data): GatewayCharge => new GatewayCharge(true, $data['tx_ref'], 'https://payments.example.test/pay'),
            );
            $gateway->shouldReceive('verify')->once()->andReturnUsing(
                static fn (string $tracking): GatewayVerification => new GatewayVerification(
                    true, StandaloneOrder::firstOrFail()->reference, $tracking, '1500000.00', 'UGX',
                ),
            );
            app()->instance(PesapalGateway::class, $gateway);

            $this->post('/standalone/checkout/'.$product->id, [
                'name' => 'Buyer Example', 'email' => 'buyer@example.test',
                'domain' => 'hospital.example.test',
            ])->assertRedirect('https://payments.example.test/pay');
            $this->get('/standalone/payment/return?OrderTrackingId=tracking-portal')
                ->assertRedirect(route('standalone.portal'));
            $this->get('/my-licence')->assertOk()->assertSee('True-Doctor 1.0.0')->assertSee('Install True-Doctor on your own server');
            $document = json_decode($this->get('/my-licence/licence.json')->assertOk()->getContent(), true);
            $this->assertTrue(app(Verifier::class)->verifiesDocument($document));
            $this->get('/my-licence/releases/'.$release->id)->assertOk();
        } finally {
            unlink($archive);
        }
    }
}
