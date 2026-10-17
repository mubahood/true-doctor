<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\StandaloneLicence;
use App\Models\StandaloneOrder;
use App\Models\StandaloneProduct;
use App\Models\StandaloneRelease;
use App\Models\User;
use App\Services\Gateway\GatewayCharge;
use App\Services\Gateway\GatewayVerification;
use App\Services\Gateway\PesapalGateway;
use App\Support\CurrentHospital;
use App\Support\Manual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** The one-off copy on sale with its receipt kept on the downloads page, and the user manual everywhere. */
class OneOffSaleAndManualTest extends TestCase
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

    public function test_the_own_copy_is_on_sale_at_one_point_two_million(): void
    {
        $product = StandaloneProduct::query()->where('slug', 'true-doctor-own-copy')->first();
        $this->assertNotNull($product, 'the migration puts it on sale');
        $this->assertSame('1200000.00', $product->price_ugx);
        $this->assertTrue($product->is_active);
        $this->assertSame(12, (int) $product->updates_months);
    }

    public function test_a_buyer_keeps_the_receipt_reference_and_manual_and_nobody_else_sees_the_receipt(): void
    {
        StandaloneRelease::create(['version' => '1.0.8', 'status' => 'published', 'published_at' => now()]);
        $product = StandaloneProduct::query()->where('slug', 'true-doctor-own-copy')->firstOrFail();
        $gateway = \Mockery::mock(PesapalGateway::class);
        $gateway->shouldReceive('initialize')->andReturnUsing(static fn (array $d): GatewayCharge => new GatewayCharge(true, $d['tx_ref'], 'https://pay.example.test'));
        $gateway->shouldReceive('verify')->andReturnUsing(static fn (string $t): GatewayVerification => new GatewayVerification(true, StandaloneOrder::firstOrFail()->reference, $t, '1200000.00', 'UGX'));
        app()->instance(PesapalGateway::class, $gateway);

        $this->post('/standalone/checkout/'.$product->id, ['name' => 'Kato Clinic', 'email' => 'owner@kato.test', 'domain' => 'hms.kato.test'])->assertRedirect('https://pay.example.test');
        $this->get('/standalone/payment/return?OrderTrackingId=trk-1')->assertRedirect(route('standalone.portal'));
        $order = StandaloneOrder::firstOrFail();

        $this->get('/my-licence')->assertOk()
            ->assertSee($order->reference)->assertSee('Receipt (PDF)')->assertSee('User manual (PDF)')
            ->assertDontSee('—');
        $pdf = $this->get(route('standalone.portal.receipt', $order))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));

        // A stranger, or a lost session, gets nothing; the email form gets a new link instead.
        $this->flushSession();
        $this->get(route('standalone.portal.receipt', $order))->assertNotFound();
        $this->post(route('standalone.portal.link'), ['email' => 'owner@kato.test'])->assertRedirect();
        $this->assertSame(1, StandaloneLicence::count());
    }

    public function test_the_manual_is_complete_and_written_without_dashes_or_emoji(): void
    {
        $chapters = Manual::chapters();
        $this->assertGreaterThanOrEqual(15, count($chapters));
        foreach (glob(Manual::path().'/*.md') ?: [] as $file) {
            $text = (string) file_get_contents($file);
            $this->assertStringNotContainsString('—', $text, basename($file));
            $this->assertStringNotContainsString('–', $text, basename($file));
            $this->assertSame(0, preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $text), basename($file).' has an emoji');
        }
    }

    public function test_the_manual_reads_on_the_site_in_the_back_office_on_the_phone_and_as_a_pdf(): void
    {
        $first = Manual::chapters()[0];
        $this->get(route('manual.index'))->assertOk()->assertSee($first['title']);
        $this->get(route('manual.show', $first['slug']))->assertOk();
        $this->get('/manual/99-nothing')->assertNotFound();
        $this->assertSame('application/pdf', $this->get(route('manual.pdf'))->assertOk()->headers->get('content-type'));

        $this->seed(\Database\Seeders\RbacSeeder::class);
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $nurse = User::factory()->create(['hospital_id' => $h->id, 'role' => 'nurse']);
        $nurse->syncSpatieRole();
        $this->actingAs($nurse)->get(route('admin.manual'))->assertOk()->assertSee($first['title']);
        $this->actingAs($nurse)->get(route('admin.manual', Manual::chapters()[1]['slug']))->assertOk();

        \Laravel\Sanctum\Sanctum::actingAs($nurse);
        $this->getJson('/api/v1/manual')->assertOk()->assertJsonPath('data.chapters.0.slug', $first['slug']);
        $this->getJson('/api/v1/manual/'.$first['slug'])->assertOk()->assertJsonPath('data.title', $first['title']);
    }
}
