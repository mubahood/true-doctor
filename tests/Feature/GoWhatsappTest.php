<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\PatientService;
use App\Services\TrafficRecorder;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** `/go/whatsapp` (GoController) and the clinic milestones (App\Support\Funnel). */
class GoWhatsappTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['support.whatsapp' => '256700111222']);
    }

    public function test_it_goes_to_whatsapp_with_the_page_named_and_is_never_an_open_redirect(): void
    {
        $to = $this->get('/go/whatsapp?c=site.page&p=dental%20clinics')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://wa.me/256700111222?text=', (string) $to);
        $this->assertStringContainsString(rawurlencode('your page about dental clinics'), (string) $to);

        // Whatever is asked for, the destination is our number.
        foreach (['/go/whatsapp?c=https://evil.example', '/go/whatsapp?c=admin.error&p=<script>', '/go/whatsapp?p='.str_repeat('x', 500)] as $url) {
            $this->assertStringStartsWith('https://wa.me/256700111222?text=', (string) $this->get($url)->assertRedirect()->headers->get('Location'));
        }
        $this->assertStringNotContainsString('<', urldecode((string) $this->get('/go/whatsapp?c=site.page&p=<script>x')->headers->get('Location')), 'the page name is plain words');
        // A page message with no page reads as the general one.
        $this->assertStringContainsString(rawurlencode('I found you on your website'), (string) $this->get('/go/whatsapp?c=site.page')->headers->get('Location'));
    }

    public function test_with_no_whatsapp_line_it_offers_the_contact_page(): void
    {
        config(['support.whatsapp' => null]);
        $this->get('/go/whatsapp')->assertRedirect(route('contact'));
    }

    public function test_public_buttons_go_through_it_and_the_tap_lands_on_the_visitors_trail(): void
    {
        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone) Safari')->withCookie(TrafficRecorder::COOKIE, 'zoe');
        $html = $this->get('/pricing')->assertOk()->getContent();
        $this->assertStringContainsString(route('go.whatsapp', ['c' => 'site.page', 'p' => 'pricing']), html_entity_decode($html));
        $this->assertStringNotContainsString('https://wa.me/', $html, 'a public button skipped the counter');
        $this->assertNull(\App\Models\TrafficSession::query()->firstOrFail()->whatsapp_at);

        $this->get('/go/whatsapp?c=site.general')->assertRedirect();
        $this->assertNotNull(\App\Models\TrafficSession::query()->firstOrFail()->whatsapp_at);
        $this->assertSame(1, \App\Models\TrafficSession::query()->count(), 'the redirect is not a page view of its own');
    }

    public function test_a_clinic_reaches_its_first_patient_and_first_paid_visit_once(): void
    {
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $h = Hospital::factory()->create(['currency' => 'UGX']);
        app(CurrentHospital::class)->set($h->id);

        app(PatientService::class)->register(['first_name' => 'Amina', 'last_name' => 'Nabirye', 'sex' => 'female']);
        $first = $h->fresh()->first_patient_at;
        $this->assertNotNull($first);
        $this->travel(5)->minutes();
        app(PatientService::class)->register(['first_name' => 'Joel', 'last_name' => 'Ssempijja', 'sex' => 'male']);
        $this->assertEquals($first, $h->fresh()->first_patient_at, 'the first patient is the first, not the latest');

        $visit = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => Patient::query()->firstOrFail()->id, 'status' => 'ongoing']);
        app(BillingService::class)->orderService($visit->fresh(), Service::factory()->create(['hospital_id' => $h->id, 'price' => '20000'])->id, 1);
        $invoice = app(BillingService::class)->generateInvoice($visit->fresh(), '0.00', null);
        app(BillingService::class)->recordPayment($invoice, \App\Enums\PaymentMethod::Cash, '5000.00');
        $this->assertNull($h->fresh()->first_paid_visit_at, 'part of a bill is not a paid visit');
        app(BillingService::class)->recordPayment(Invoice::query()->findOrFail($invoice->id), \App\Enums\PaymentMethod::Cash, '15000.00');
        $this->assertNotNull($h->fresh()->first_paid_visit_at);
    }
}
