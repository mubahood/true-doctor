<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\TrafficEvent;
use App\Models\TrafficSession;
use App\Services\TrafficRecorder;
use App\Support\LandingIntent;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ad clicks: where they land, and what is written down.
 *
 * The campaign spec's hard rule is the first block below — **no URL in the
 * campaign may ever 404 or error.** Google rejects a campaign whose landing
 * pages fail, so a typo in an ad has to degrade to the front door rather
 * than to a stack trace.
 *
 * The second rule is this file's own: recording a visit must never be able to
 * break the visit.
 */
class LandingTrafficTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(PlanSeeder::class);

        // The "See a Live Demo" sitelink points at /test-login, which exists
        // only where the demo accounts do. Production has them; so does this.
        config()->set('demo.enabled', true);
        $this->seed(\Database\Seeders\DemoSeeder::class);
    }

    /** A browser, so nothing is filed as a crawler. */
    private function asBrowser(): static
    {
        return $this->withHeader(
            'User-Agent',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36',
        );
    }

    // ── Every URL in the campaign ────────────────────────────────────────

    public static function campaignUrls(): array
    {
        return [
            'main ad' => ['/', null],
            'pricing sitelink' => ['/?section=pricing', '/pricing'],
            'trial sitelink' => ['/?section=signup', '/register'],
            'demo sitelink' => ['/?section=demo', '/test-login'],
            'security sitelink' => ['/?section=security', '/security'],
            'contact sitelink' => ['/?section=contact', '/contact#enquiry'],
            'patients module' => ['/?section=modules&module=patients', '/features?module=patients#patients'],
            'appointments module' => ['/?section=modules&module=appointments', '/features?module=appointments#appointments'],
            'pharmacy module' => ['/?section=modules&module=pharmacy', '/features?module=pharmacy#pharmacy'],
            'lab module' => ['/?section=modules&module=lab-radiology', '/features?module=lab-radiology#lab-radiology'],
            'inpatient module' => ['/?section=modules&module=inpatient', '/features?module=inpatient#inpatient'],
            'billing module' => ['/?section=modules&module=billing', '/features?module=billing#billing'],
            'offline module' => ['/?section=modules&module=offline', '/features?module=offline#offline'],
            'starter price asset' => ['/?section=pricing&plan=starter', '/pricing?plan=starter'],
            'professional price asset' => ['/?section=pricing&plan=professional', '/pricing?plan=professional'],
            'enterprise price asset' => ['/?section=pricing&plan=enterprise', '/pricing?plan=enterprise'],
        ];
    }

    /**
     * @dataProvider campaignUrls
     */
    public function test_every_campaign_url_lands_somewhere_real(string $url, ?string $expected): void
    {
        $response = $this->asBrowser()->get($url);

        if ($expected === null) {
            $response->assertOk();

            return;
        }

        $response->assertRedirect(url($expected));

        // And the destination is a real page, not a redirect into nothing.
        $this->asBrowser()->get($expected)->assertOk();
    }

    /** The tracking suffix Google adds to every click, on every sitelink. */
    public function test_the_tracking_suffix_survives_the_redirect(): void
    {
        $suffix = 'utm_source=google&utm_medium=cpc&utm_campaign=true-doctor-search&utm_content=rsa-hms&gclid=Cj0KabcDEF123';

        $location = $this->asBrowser()
            ->get('/?section=pricing&plan=professional&'.$suffix)
            ->assertRedirect()
            ->headers->get('Location');

        // Lost here, the destination looks like direct traffic and the
        // attribution is gone between one page and the next.
        foreach (['utm_source=google', 'utm_medium=cpc', 'utm_campaign=true-doctor-search', 'utm_content=rsa-hms', 'gclid=Cj0KabcDEF123', 'plan=professional'] as $fragment) {
            $this->assertStringContainsString($fragment, (string) $location);
        }
    }

    /** Unknown values fall back to the home page. Never an error. */
    public function test_nonsense_parameters_never_produce_an_error(): void
    {
        foreach ([
            '/?section=wat',
            '/?section=modules&module=nope',
            '/?section=pricing&plan=platinum',
            '/?section=&module=&plan=',
            '/?section[]=array&module[]=array',
            '/?gclid='.str_repeat('x', 4000),
            '/?utm_source='.urlencode("line\nbreak\r\0null"),
            '/?section=%00%01%02',
        ] as $url) {
            $status = $this->asBrowser()->get($url)->baseResponse->getStatusCode();

            $this->assertContains($status, [200, 302], "{$url} answered {$status}");
        }
    }

    public function test_a_bad_section_stays_on_the_home_page(): void
    {
        $this->asBrowser()->get('/?section=wat')
            ->assertOk()
            ->assertSee('hospital management system', false)
            ->assertSee('replaces your paper registers');
    }

    public function test_a_bad_module_still_reaches_the_product_page(): void
    {
        $this->asBrowser()->get('/?section=modules&module=nope')->assertRedirect(url('/features'));
    }

    /** The ad headline has to be findable on the page it lands on. */
    public function test_the_hero_says_what_the_ad_said(): void
    {
        $html = $this->asBrowser()->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<h1[^>]*>.*?Hospital Management System.*?<\/h1>/si',
            preg_replace('/<div class="eyebrow">.*?<\/div>/s', '', $html) ?? $html,
            'the H1 does not carry the ad headline',
        );
    }

    /** `/product` is the address in the campaign; it must not 404. */
    public function test_the_product_alias_resolves(): void
    {
        $this->asBrowser()->get('/product')->assertRedirect(url('/features'));
        $this->asBrowser()->get('/features')->assertOk();
    }

    // ── What the page does with it ───────────────────────────────────────

    public function test_the_product_page_carries_an_anchor_for_every_module(): void
    {
        $html = $this->asBrowser()->get('/features')->assertOk()->getContent();

        foreach (LandingIntent::MODULES as $module) {
            $this->assertStringContainsString('id="'.$module.'"', $html, "no anchor for {$module}");
        }
    }

    public function test_the_clicked_plan_is_marked_on_the_pricing_page(): void
    {
        $this->asBrowser()->get('/pricing?plan=professional')
            ->assertOk()
            ->assertSee('The plan you clicked')
            ->assertSee('is-picked', false);
    }

    public function test_the_clicked_plan_is_preselected_on_the_sign_up_form(): void
    {
        $professional = \App\Models\Plan::where('slug', 'professional')->firstOrFail();

        $html = $this->asBrowser()->get('/register?plan=professional')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/value="'.$professional->id.'"\s+checked/', $html);
    }

    // ── What gets written down ───────────────────────────────────────────

    public function test_an_ad_click_is_recorded_with_its_attribution(): void
    {
        $this->asBrowser()->get('/?section=pricing&utm_source=google&utm_medium=cpc&utm_campaign=true-doctor-search&utm_content=rsa-hms&gclid=ABC123');

        $session = TrafficSession::sole();

        $this->assertSame('google', $session->utm_source);
        $this->assertSame('cpc', $session->utm_medium);
        $this->assertSame('true-doctor-search', $session->utm_campaign);
        $this->assertSame('rsa-hms', $session->utm_content);
        $this->assertSame('ABC123', $session->gclid);
        $this->assertSame('pricing', $session->landing_section);
        $this->assertFalse($session->is_bot);

        // The hit on `/` was a redirect to /pricing: an ad click, but not a
        // page anybody read.
        $this->assertSame(1, $session->clicks);
        $this->assertSame(0, $session->page_views);
        $this->assertSame('redirect', TrafficEvent::sole()->kind);
    }

    /**
     * The click is recorded as the SITELINK, not as the destination.
     *
     * Record only the destination and the campaign report says the home page
     * brought nobody and /pricing brought everybody, which is the opposite of
     * what happened.
     */
    public function test_the_sitelink_is_recorded_not_only_where_it_sent_them(): void
    {
        $this->asBrowser()->get('/?section=modules&module=pharmacy&utm_source=google');

        $session = TrafficSession::sole();

        $this->assertSame('modules', $session->landing_section);
        $this->assertSame('pharmacy', $session->landing_module);
        $this->assertSame('/', $session->landing_path);
    }

    public function test_the_trail_records_each_page(): void
    {
        $cookie = TrafficRecorder::COOKIE;

        // One visitor, three pages.
        $this->asBrowser()->withCookie($cookie, 'fixed-visitor')->get('/');
        $this->asBrowser()->withCookie($cookie, 'fixed-visitor')->get('/pricing');
        $this->asBrowser()->withCookie($cookie, 'fixed-visitor')->get('/features');

        $session = TrafficSession::sole();

        $this->assertSame(3, $session->page_views, 'the three hits were not recognised as one visitor');
        $this->assertSame(
            ['/', '/pricing', '/features'],
            $session->events()->orderBy('id')->pluck('path')->all(),
        );
    }

    public function test_a_crawler_is_recorded_but_flagged(): void
    {
        $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')
            ->get('/');

        $this->assertTrue(TrafficSession::sole()->is_bot);
    }

    public function test_an_asset_request_is_not_a_page_view(): void
    {
        $this->asBrowser()->get('/robots.txt');
        $this->asBrowser()->get('/sitemap.xml');

        $this->assertSame(0, TrafficSession::count());
    }

    /** No raw address, ever. */
    public function test_the_ip_address_is_never_stored_in_the_clear(): void
    {
        $this->asBrowser()->withServerVariables(['REMOTE_ADDR' => '41.210.146.249'])->get('/');

        $session = TrafficSession::sole();

        $this->assertNotNull($session->ip_hash);
        $this->assertStringNotContainsString('41.210.146.249', $session->ip_hash);
        $this->assertStringNotContainsString('41.210.146.249', json_encode($session->toArray()) ?: '');
        $this->assertSame(64, strlen($session->ip_hash), 'not a sha256 hash');
    }

    /** A parameterised URL must not be indexed as a page of its own. */
    public function test_a_parameterised_url_is_not_indexed(): void
    {
        $this->asBrowser()->get('/pricing?plan=starter&utm_source=google')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, follow');

        // …and the clean address is indexable as normal.
        $response = $this->asBrowser()->get('/pricing');
        $this->assertNull($response->headers->get('X-Robots-Tag'));
    }

    // ── Recording may never break the page ───────────────────────────────

    /**
     * The rule this is all built around. A public page that 500s because an
     * analytics insert failed is a page costing money on a live campaign.
     */
    public function test_a_broken_recorder_does_not_break_the_page(): void
    {
        $this->partialMock(TrafficRecorder::class, function ($mock) {
            $mock->shouldReceive('record')->andThrow(new \RuntimeException('the table is gone'));
        });

        $this->asBrowser()->get('/')->assertOk();
        $this->asBrowser()->get('/pricing')->assertOk();
    }

    // ── A sign-up is attributed to the click that earned it ──────────────

    public function test_a_sign_up_carries_the_advertisement_that_brought_it(): void
    {
        $plan = \App\Models\Plan::where('slug', 'starter')->firstOrFail();

        // The ad click.
        $this->asBrowser()
            ->withCookie(TrafficRecorder::COOKIE, 'visitor-1')
            ->get('/?section=signup&utm_source=google&utm_medium=cpc&utm_campaign=true-doctor-search&utm_content=rsa-hms&gclid=CjTEST999');

        // …and the sign-up, several pages later.
        $this->asBrowser()
            ->withCookie(TrafficRecorder::COOKIE, 'visitor-1')
            ->post('/register', [
                'hospital_name' => 'Attributed Clinic',
                'name' => 'Sarah Nakato',
                'email' => 'sarah@attributed.test',
                'password' => 'password1',
                'password_confirmation' => 'password1',
                'plan_id' => $plan->id,
            ])->assertRedirect();

        $hospital = Hospital::where('name', 'Attributed Clinic')->firstOrFail();

        $this->assertSame('google', $hospital->utm_source);
        $this->assertSame('cpc', $hospital->utm_medium);
        $this->assertSame('true-doctor-search', $hospital->utm_campaign);
        $this->assertSame('rsa-hms', $hospital->utm_content);
        $this->assertSame('CjTEST999', $hospital->gclid);
        $this->assertNotNull($hospital->attributed_at);

        // …and the visit that brought them knows what it became.
        $session = TrafficSession::where('gclid', 'CjTEST999')->sole();
        $this->assertSame($hospital->id, $session->converted_hospital_id);
        $this->assertNotNull($session->converted_at);
    }

    /** First touch wins: the ad that introduced them earned the sign-up. */
    public function test_the_first_advertisement_keeps_the_credit(): void
    {
        $plan = \App\Models\Plan::where('slug', 'starter')->firstOrFail();
        $cookie = TrafficRecorder::COOKIE;

        $this->asBrowser()->withCookie($cookie, 'v2')->get('/?utm_source=google&utm_campaign=first-campaign&gclid=FIRST');
        $this->asBrowser()->withCookie($cookie, 'v2')->get('/?utm_source=facebook&utm_campaign=second-campaign&gclid=SECOND');

        $this->asBrowser()->withCookie($cookie, 'v2')->post('/register', [
            'hospital_name' => 'First Touch Clinic',
            'name' => 'A', 'email' => 'a@first.test',
            'password' => 'password1', 'password_confirmation' => 'password1',
            'plan_id' => $plan->id,
        ])->assertRedirect();

        $hospital = Hospital::where('name', 'First Touch Clinic')->firstOrFail();

        $this->assertSame('first-campaign', $hospital->utm_campaign);
        $this->assertSame('FIRST', $hospital->gclid);
    }

    /** Signing up with no campaign at all must still work. */
    public function test_a_sign_up_with_no_attribution_still_works(): void
    {
        $plan = \App\Models\Plan::where('slug', 'starter')->firstOrFail();

        $this->asBrowser()->post('/register', [
            'hospital_name' => 'Organic Clinic',
            'name' => 'B', 'email' => 'b@organic.test',
            'password' => 'password1', 'password_confirmation' => 'password1',
            'plan_id' => $plan->id,
        ])->assertRedirect();

        $hospital = Hospital::where('name', 'Organic Clinic')->firstOrFail();

        $this->assertNull($hospital->gclid);
        $this->assertNull($hospital->utm_source);
    }

    // ── Nothing here belongs to a hospital ───────────────────────────────

    /**
     * Traffic is PLATFORM data. A tenant scope on it would make it invisible
     * to the only person it is for — and a hospital_id on it would invite
     * somebody to try showing it inside a tenant one day.
     */
    public function test_traffic_is_not_tenant_data(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('traffic_sessions', 'hospital_id'),
            'traffic_sessions has a hospital_id — it is platform data, not a tenant\'s',
        );
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('traffic_events', 'hospital_id'));

        $this->assertArrayNotHasKey(
            \App\Models\Scopes\HospitalScope::class,
            (new TrafficSession)->getGlobalScopes(),
        );
    }

    public function test_events_go_when_their_session_does(): void
    {
        $this->asBrowser()->get('/');

        $session = TrafficSession::sole();
        $this->assertSame(1, TrafficEvent::count());

        $session->delete();

        $this->assertSame(0, TrafficEvent::count(), 'events outlived the session they belong to');
    }

    // ── A first-time visitor, as a real browser is one ───────────────────
    // Every test above sends the visitor cookie from the very first request.
    // A real first-time visitor has none, and that is the case that matters:
    // it is what every ad click from somebody new looks like.

    private function cookieFrom(\Illuminate\Testing\TestResponse $response): string
    {
        $cookie = $response->getCookie(TrafficRecorder::COOKIE);
        $this->assertNotNull($cookie, 'no visitor cookie was set');

        return (string) $cookie->getValue();
    }

    private function signUp(string $name, ?string $cookie = null): \Illuminate\Testing\TestResponse
    {
        $plan = \App\Models\Plan::where('slug', 'starter')->firstOrFail();
        $request = $this->asBrowser();

        if ($cookie !== null) {
            $request = $request->withCookie(TrafficRecorder::COOKIE, $cookie);
        }

        return $request->post('/register', [
            'hospital_name' => $name,
            'name' => 'Owner', 'email' => \Illuminate\Support\Str::slug($name).'@signup.test',
            'password' => 'password1', 'password_confirmation' => 'password1',
            'plan_id' => $plan->id,
        ])->assertRedirect();
    }

    /**
     * The first hit had no cookie, so it used to be keyed one way and every
     * hit after it — the sign-up included — another. The ad click sat on a
     * row nobody ever converted, and the hospital was filed as direct.
     */
    public function test_a_first_time_visitor_is_one_visitor_from_ad_click_to_sign_up(): void
    {
        $first = $this->asBrowser()->get('/?section=signup&utm_source=google&utm_medium=cpc&utm_campaign=true-doctor-search&utm_content=rsa-hms&gclid=CjFIRSTVISIT01');
        $id = $this->cookieFrom($first);

        $this->asBrowser()->withCookie(TrafficRecorder::COOKIE, $id)->get((string) $first->headers->get('Location'))->assertOk();
        $this->asBrowser()->withCookie(TrafficRecorder::COOKIE, $id)->get('/pricing')->assertOk();
        $this->signUp('First Visit Clinic', $id);

        $this->assertSame(1, TrafficSession::count(), 'the ad click and the pages after it were filed as different visitors');

        $session = TrafficSession::sole();
        $this->assertSame('CjFIRSTVISIT01', $session->gclid);
        $this->assertSame(1, $session->clicks);
        $this->assertSame(2, $session->page_views, 'the sign-up form and the pricing page');
        $this->assertNotNull($session->opened_signup_at);
        $this->assertNotNull($session->viewed_pricing_at);

        $hospital = Hospital::where('name', 'First Visit Clinic')->firstOrFail();
        $this->assertSame($hospital->id, $session->converted_hospital_id);
        $this->assertSame('CjFIRSTVISIT01', $hospital->gclid);
        $this->assertSame('signup', $session->landing_section);

        $this->assertSame(['redirect', 'view', 'view', 'signup'], $session->events()->orderBy('id')->pluck('kind')->all());
    }

    /** A browser that refuses the cookie still gets its sign-up attributed. */
    public function test_the_session_carries_the_attribution_when_the_cookie_does_not(): void
    {
        $this->withSession(['attribution' => [
            'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'true-doctor-search',
            'gclid' => 'CjNOCOOKIE01', 'landing_section' => 'pricing', 'landing_plan' => 'starter',
        ]]);

        $this->signUp('Cookieless Clinic');

        $hospital = Hospital::where('name', 'Cookieless Clinic')->firstOrFail();
        $this->assertSame('CjNOCOOKIE01', $hospital->gclid);
        $this->assertSame('starter', $hospital->landing_plan);
    }

    // ── Counting clicks the way Google bills them ────────────────────────

    public function test_a_sitelink_click_is_one_click_across_the_redirect(): void
    {
        $cookie = TrafficRecorder::COOKIE;

        $first = $this->asBrowser()->withCookie($cookie, 'v-redirect')
            ->get('/?section=modules&module=pharmacy&utm_source=google&utm_medium=cpc&gclid=CjREDIRECT01');
        $this->asBrowser()->withCookie($cookie, 'v-redirect')->get((string) $first->headers->get('Location'))->assertOk();

        $session = TrafficSession::sole();
        $this->assertSame(1, $session->clicks, 'the redirect and its destination were counted as two clicks');
        $this->assertSame(1, $session->page_views);

        [$redirect, $view] = $session->events()->orderBy('id')->get()->all();

        $this->assertSame('redirect', $redirect->kind);
        $this->assertSame(302, $redirect->status);
        $this->assertSame('/features#pharmacy', $redirect->redirect_to);
        $this->assertTrue($redirect->is_click);
        $this->assertSame('gclid', $redirect->click_id_type);

        $this->assertSame('view', $view->kind);
        $this->assertSame('/features', $view->path);
        $this->assertSame(200, $view->status);
        $this->assertFalse($view->is_click);
        $this->assertNotNull($view->duration_ms);
    }

    public function test_a_reload_is_not_another_click_but_a_second_ad_is(): void
    {
        $cookie = TrafficRecorder::COOKIE;

        $this->asBrowser()->withCookie($cookie, 'v-reload')->get('/pricing?gclid=CjRELOADAAA');
        $this->asBrowser()->withCookie($cookie, 'v-reload')->get('/pricing?gclid=CjRELOADAAA');
        $this->assertSame(1, TrafficSession::sole()->clicks, 'a reload was counted as a second click');

        $this->asBrowser()->withCookie($cookie, 'v-reload')->get('/security?gclid=CjRELOADBBB');
        $this->assertSame(2, TrafficSession::sole()->clicks, 'a second ad click was not counted');

        // Tagged without a click id: a reload is still the same arrival.
        $this->asBrowser()->withCookie($cookie, 'v-utm')->get('/?utm_source=newsletter&utm_campaign=sept');
        $this->asBrowser()->withCookie($cookie, 'v-utm')->get('/?utm_source=newsletter&utm_campaign=sept');
        $this->assertSame(1, TrafficSession::where('utm_source', 'newsletter')->sole()->clicks);
    }

    /** iOS clicks often carry gbraid/wbraid INSTEAD of gclid. */
    public function test_an_ios_click_id_is_kept_and_counted_as_paid(): void
    {
        $this->asBrowser()->withCookie(TrafficRecorder::COOKIE, 'v-ios')->get('/?utm_source=google&gbraid=0AAAAAiosCLICK');
        $this->signUp('iPhone Clinic', 'v-ios');

        $session = TrafficSession::sole();
        $this->assertSame('0AAAAAiosCLICK', $session->gbraid);
        $this->assertTrue($session->isPaid());
        $this->assertSame(1, TrafficSession::query()->paid()->count());

        $this->assertSame('0AAAAAiosCLICK', Hospital::where('name', 'iPhone Clinic')->firstOrFail()->gbraid);
    }

    public function test_the_redirect_carries_every_tracking_parameter(): void
    {
        $location = (string) $this->asBrowser()
            ->get('/?section=pricing&gbraid=0AAAios&gad_source=1&gad_campaignid=2233&keyword=hms')
            ->headers->get('Location');

        foreach (['gbraid=0AAAios', 'gad_source=1', 'gad_campaignid=2233', 'keyword=hms'] as $part) {
            $this->assertStringContainsString($part, $location);
        }
    }

    /** What the ad's tracking template says is kept — from an allow-list, and nothing else. */
    public function test_ad_parameters_are_kept_from_an_allow_list_only(): void
    {
        $this->asBrowser()->get('/?utm_source=google&keyword=hospital+software&matchtype=e&network=g&gad_source=1&evil=%3Cscript%3E');

        $event = TrafficEvent::sole();
        $this->assertSame('hospital software', $event->keyword);
        $kept = $event->ad_params;
        ksort($kept);
        $this->assertSame(
            ['gad_source' => '1', 'keyword' => 'hospital software', 'matchtype' => 'e', 'network' => 'g'],
            $kept,
            'evil= was stored, or an allowed parameter was lost',
        );
        $this->assertSame('hospital software', TrafficSession::sole()->utm_term);
    }

    public function test_source_and_medium_are_counted_whatever_their_case(): void
    {
        $this->asBrowser()->get('/?utm_source=Google&utm_medium=CPC&utm_campaign=True-Doctor');

        $session = TrafficSession::sole();
        $this->assertSame('google', $session->utm_source);
        $this->assertSame('cpc', $session->utm_medium);
        $this->assertSame('True-Doctor', $session->utm_campaign, 'a campaign is a name and keeps its case');
        $this->assertSame(1, TrafficSession::query()->paid()->count());
    }

    // ── The steps between arriving and signing up ────────────────────────

    public function test_each_step_towards_signing_up_is_timestamped(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $cookie = TrafficRecorder::COOKIE;

        $this->asBrowser()->withCookie($cookie, 'v-steps')->get('/?utm_source=google&gclid=CjSTEPS001');
        $this->asBrowser()->withCookie($cookie, 'v-steps')->get('/pricing');
        $this->asBrowser()->withCookie($cookie, 'v-steps')->get('/test-login')->assertOk();
        $this->asBrowser()->withCookie($cookie, 'v-steps')->get('/register');
        $this->asBrowser()->withCookie($cookie, 'v-steps')->post(route('contact.send'), [
            'name' => 'Sarah', 'email' => 'sarah@clinic.test',
            'message' => 'We would like a walkthrough of the pharmacy module.',
        ])->assertRedirect(route('contact'));

        $session = TrafficSession::sole();
        $this->assertNotNull($session->viewed_pricing_at);
        $this->assertNotNull($session->opened_demo_at);
        $this->assertNotNull($session->opened_signup_at);
        $this->assertNotNull($session->enquired_at);
        $this->assertSame('enquiry', $session->events()->latest('id')->first()->kind);
    }

    /** A landing URL that fails is written down as failing, not as a view. */
    public function test_a_failing_landing_url_is_recorded_with_its_status(): void
    {
        config()->set('demo.enabled', false);

        $this->asBrowser()->withCookie(TrafficRecorder::COOKIE, 'v-404')->get('/test-login')->assertNotFound();

        $event = TrafficEvent::sole();
        $this->assertSame(404, $event->status);
        $this->assertSame(0, TrafficSession::sole()->page_views, 'an error page counted as a page read');
        $this->assertNull(TrafficSession::sole()->opened_demo_at, 'a 404 counted as opening the demo');
    }

    public function test_a_crawler_gets_no_cookie_and_stays_one_row(): void
    {
        $bot = 'Mozilla/5.0 (compatible; AdsBot-Google; +http://www.google.com/adsbot.html)';

        $response = $this->withHeader('User-Agent', $bot)->get('/?gclid=CjADSBOT');
        $this->assertNull($response->getCookie(TrafficRecorder::COOKIE));

        $this->withHeader('User-Agent', $bot)->get('/pricing');

        $session = TrafficSession::sole();
        $this->assertTrue($session->is_bot);
        $this->assertSame(2, $session->page_views);
    }

    public function test_a_broken_recorder_still_leaves_the_visitor_recognisable(): void
    {
        $this->partialMock(TrafficRecorder::class, function ($mock) {
            $mock->shouldReceive('record')->andThrow(new \RuntimeException('the table is gone'));
        });

        $this->assertNotNull($this->asBrowser()->get('/')->assertOk()->getCookie(TrafficRecorder::COOKIE));
    }

    public function test_malformed_text_in_a_url_is_dropped_not_stored(): void
    {
        $this->asBrowser()->get('/?utm_source=%C3%28&utm_campaign=ok&gclid=%FF%FE')->assertOk();

        $session = TrafficSession::sole();
        $this->assertNull($session->utm_source);
        $this->assertNull($session->gclid);
        $this->assertSame('ok', $session->utm_campaign);
    }
}
