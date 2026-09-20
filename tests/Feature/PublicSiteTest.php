<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Notifications\PublicEnquiryReceived;
use App\Support\PlatformPrice;
use App\Support\VisitorRegion;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The public site.
 *
 * Three things are held here. That every page renders and says what it is
 * for. That the price a visitor is quoted follows where they appear to be,
 * can be corrected in one click, and is never quietly wrong. And that the
 * contact form reaches somebody without also being a spam relay.
 */
class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        config()->set('services.pesapal.usd_to_ugx_rate', 3800);
        config()->set('pricing.base_currency', 'UGX');
    }

    /** @return list<string> */
    private function pages(): array
    {
        return ['home', 'features', 'pricing', 'security', 'contact', 'privacy', 'terms'];
    }

    // ── Every page stands up ─────────────────────────────────────────────

    public function test_every_public_page_renders(): void
    {
        foreach ($this->pages() as $name) {
            $this->get(route($name))->assertOk();
        }
    }

    public function test_every_page_says_what_it_is(): void
    {
        // A page with no title and no description is a page a search engine
        // and a shared link both render as a bare URL.
        foreach ($this->pages() as $name) {
            $html = $this->get(route($name))->getContent();

            $this->assertMatchesRegularExpression('/<title>.{12,}<\/title>/', $html, "{$name} has no real title");
            $this->assertMatchesRegularExpression(
                '/<meta name="description" content="[^"]{40,}"/',
                $html,
                "{$name} has no description worth showing",
            );
            $this->assertStringContainsString('rel="canonical"', $html, "{$name} has no canonical URL");
        }
    }

    public function test_every_page_has_exactly_one_h1(): void
    {
        foreach ($this->pages() as $name) {
            $html = $this->get(route($name))->getContent();

            $this->assertSame(
                1,
                preg_match_all('/<h1[\s>]/', $html),
                "{$name} does not have exactly one <h1>",
            );
        }
    }

    public function test_every_page_offers_a_way_past_the_header(): void
    {
        foreach ($this->pages() as $name) {
            $this->get(route($name))
                ->assertSee('Skip to content')
                ->assertSee('id="main"', false);
        }
    }

    /** The page you are on should say so, and not only by being a different colour. */
    public function test_the_current_page_is_marked_in_the_navigation(): void
    {
        $this->get(route('pricing'))->assertSee('aria-current="page"', false);
    }

    // ── The hero pattern ─────────────────────────────────────────────────

    /**
     * The patterned background is a data-URI in the stylesheet, so it cannot
     * 404 and costs no request. Asserted on the BUILT css, because that is
     * what a browser is served — a rule that only exists in the source is a
     * rule nobody sees.
     */
    public function test_the_hero_carries_its_pattern_in_the_built_stylesheet(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        $built = $manifest['resources/css/marketing.css']['file'] ?? null;

        $this->assertNotNull($built, 'marketing.css is not in the build manifest — run npm run build');

        $css = (string) file_get_contents(public_path('build/'.$built));

        // `:before`, not `::before` — the minifier rewrites it to the single
        // colon form, and this asserts on what a browser is actually served.
        $this->assertStringContainsString('.hero:before', $css, 'the hero has no pattern layer');
        $this->assertStringContainsString('data:image/svg+xml', $css, 'the hero pattern is not inline');
        $this->assertStringContainsString('mask-image', $css, 'the pattern is not faded at its edges');
    }

    // ── Movement, and the promise that it cannot hide anything ───────────

    /**
     * Content may only be hidden when the script that reveals it has run.
     *
     * The reveal styles are scoped to a `.js` class. If that class were in
     * the markup, a page whose JavaScript 404'd after a bad deploy would
     * render as a column of invisible sections — and nobody would find out
     * from a 200. So it is added by an inline script that also removes it
     * again if the site bundle never checks in.
     */
    public function test_nothing_is_hidden_unless_the_script_that_reveals_it_ran(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        // The class is not baked into the served markup...
        $this->assertDoesNotMatchRegularExpression(
            '/<html[^>]*class="[^"]*\bjs\b/',
            $html,
            'the `js` class is in the markup, so a failed script leaves the page blank',
        );

        // ...it is added by script, with a timeout that undoes it.
        $this->assertStringContainsString("classList.add('js')", $html);
        $this->assertStringContainsString('data-site-ready', $html);
        $this->assertMatchesRegularExpression('/setTimeout\(.*classList\.remove\(.js.\)/s', $html);
    }

    public function test_the_reveal_styles_are_scoped_and_respect_reduced_motion(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        $css = (string) file_get_contents(public_path('build/'.$manifest['resources/css/marketing.css']['file']));

        // Every hiding rule is behind `.js`.
        preg_match_all('/([^{}]*)\{[^{}]*opacity:0[^{}]*\}/', $css, $matches);
        foreach ($matches[1] as $selector) {
            if (! str_contains($selector, 'data-reveal') && ! str_contains($selector, 'data-lift')) {
                continue;
            }
            $this->assertStringContainsString(
                '.js',
                $selector,
                "a reveal rule hides `{$selector}` without requiring JavaScript",
            );
        }

        // And reduced motion switches them off rather than speeding them up.
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertMatchesRegularExpression(
            '/prefers-reduced-motion[^@]*opacity:1\s*!important/s',
            $css,
            'reduced motion does not force revealed elements visible',
        );
    }

    /** Movement is composited only — nothing below it may jump while it plays. */
    public function test_the_reveal_animates_nothing_that_moves_the_layout(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        $css = (string) file_get_contents(public_path('build/'.$manifest['resources/css/marketing.css']['file']));

        preg_match_all('/transition:([^;}]*)/', $css, $matches);

        foreach ($matches[1] as $value) {
            foreach (['height', 'width', 'margin', 'padding', 'top', 'left', 'font-size'] as $reflowing) {
                $this->assertStringNotContainsString(
                    $reflowing,
                    $value,
                    "transitioning `{$reflowing}` reflows the page while it animates",
                );
            }
        }
    }

    // ── Which currency, and why ──────────────────────────────────────────

    public function test_a_visitor_with_no_signals_is_quoted_in_dollars(): void
    {
        $this->get(route('pricing'))
            ->assertOk()
            ->assertSee('Shown in US dollars because you appear to be outside East Africa.');
    }

    /**
     * A browser set to a East African locale is a real signal, and the last
     * one the resolver tries before giving up.
     */
    public function test_an_east_african_browser_is_quoted_in_shillings(): void
    {
        $this->withHeader('Accept-Language', 'en-UG,en;q=0.9')
            ->get(route('pricing'))
            ->assertOk()
            ->assertSee('Shown in shillings because you appear to be in East Africa.');
    }

    public function test_a_browser_with_no_region_in_its_language_is_not_guessed_at(): void
    {
        // Plain `en` says nothing about where somebody is, and inventing a
        // country from it would be worse than falling back.
        $this->withHeader('Accept-Language', 'en;q=0.9,fr')
            ->get(route('pricing'))
            ->assertOk()
            ->assertSee('outside East Africa');
    }

    public function test_the_rate_is_the_one_the_hospital_set(): void
    {
        $this->assertSame('$2.63', PlatformPrice::make('10000', 'USD')->label());
        $this->assertSame('USh 10,000', PlatformPrice::make('10000', 'UGX')->label());
        // Twelve months, converted — not converted then multiplied by a
        // rounded figure, which is how a yearly total ends up off by cents.
        $this->assertSame('$31.58', PlatformPrice::make('10000', 'USD')->yearly());
    }

    /**
     * One rate for the whole platform.
     *
     * The gap this closes: the public pricing page and the subscription
     * checkout each had their own shilling-to-dollar figure — 3800 and 3600.
     * A hospital would have read one price on the way in and been charged
     * against another on the screen where they paid. Whatever the rate is,
     * both have to be reading the same one.
     */
    public function test_the_public_page_and_the_checkout_use_the_same_rate(): void
    {
        config()->set('services.pesapal.usd_to_ugx_rate', 4000);

        $quoted = PlatformPrice::make('40000', 'USD')->label();
        $charged = \App\Support\PlatformCurrency::toUsd('40000');

        $this->assertSame('$10.00', $quoted);
        $this->assertSame('10.00', $charged, 'the checkout converts at a different rate from the pricing page');
        $this->assertSame(4000.0, \App\Support\PlatformCurrency::rate());
    }

    /**
     * Every page that quotes a plan quotes it identically.
     *
     * The gap this closes: the sign-up form rendered `'$'.number_format(price)`
     * — a dollar sign in front of a shilling figure — and advertised the
     * Starter plan at TEN THOUSAND DOLLARS a month, on the same screen where
     * somebody types their card details, while the pricing page one click
     * away said $2.63.
     */
    public function test_the_pricing_page_and_the_sign_up_form_quote_identically(): void
    {
        foreach ([['en-GB', 'USD'], ['en-UG', 'UGX']] as [$language, $currency]) {
            $pricing = $this->withHeader('Accept-Language', $language)->get(route('pricing'))->getContent();
            $register = $this->withHeader('Accept-Language', $language)->get(route('register'))->getContent();

            foreach (Plan::where('is_active', true)->get() as $plan) {
                $label = PlatformPrice::make($plan->price, $currency)->label();

                $this->assertStringContainsString($label, $pricing, "pricing does not quote {$plan->name} as {$label}");
                $this->assertStringContainsString($label, $register, "sign-up does not quote {$plan->name} as {$label}");
            }
        }
    }

    /** A shilling figure must never be printed with a dollar sign on it. */
    public function test_no_public_page_prints_a_shilling_figure_as_dollars(): void
    {
        // Plan prices are four, five and six figures in shillings. A dollar
        // amount in the thousands on a page selling clinic software is a
        // shilling figure that has been mislabelled.
        foreach (['pricing', 'register'] as $page) {
            $html = $this->withHeader('Accept-Language', 'en-GB')->get(route($page))->getContent();

            $this->assertDoesNotMatchRegularExpression(
                '/\$\s?\d{1,3},\d{3}(?!\d)/',
                $html,
                "{$page} prints a thousands-scale dollar figure — almost certainly shillings with a \$ on them",
            );
        }
    }

    public function test_a_converted_price_says_it_is_converted(): void
    {
        $this->get(route('pricing'))
            ->assertOk()
            ->assertSee('Converted at USh 3,800 to US$1.', false);
    }

    public function test_a_shilling_price_does_not_carry_a_conversion_note(): void
    {
        $this->withHeader('Accept-Language', 'en-KE')
            ->get(route('pricing'))
            ->assertOk()
            ->assertDontSee('Converted at USh');
    }

    /** Every country in the list has to actually produce shillings. */
    public function test_every_east_african_country_is_quoted_in_shillings(): void
    {
        foreach (config('pricing.east_africa') as $country) {
            $region = new VisitorRegion(tap(request(), fn ($r) => $r->headers->set('Accept-Language', 'en-'.$country)));

            $this->assertSame('UGX', $region->currency(), "{$country} is not being quoted in shillings");
        }
    }

    public function test_somewhere_outside_east_africa_is_quoted_in_dollars(): void
    {
        foreach (['GB', 'US', 'NG', 'ZA', 'IN', 'DE'] as $country) {
            $region = new VisitorRegion(tap(request(), fn ($r) => $r->headers->set('Accept-Language', 'en-'.$country)));

            $this->assertSame('USD', $region->currency(), "{$country} is being quoted in shillings");
        }
    }

    // ── Choosing beats guessing ──────────────────────────────────────────

    public function test_a_chosen_currency_overrides_the_guess(): void
    {
        // The browser says Uganda; the visitor asked for dollars. The visitor
        // wins, because a guess somebody has corrected is not a guess.
        $this->withHeader('Accept-Language', 'en-UG')
            ->withCookie(VisitorRegion::COOKIE, 'USD')
            ->get(route('pricing'))
            ->assertOk()
            ->assertSee('Your choice, remembered on this device.');
    }

    public function test_the_switch_sets_the_cookie_and_sends_you_back(): void
    {
        $this->from(route('pricing'))
            ->post(route('currency'), ['currency' => 'USD'])
            ->assertRedirect(route('pricing'))
            ->assertCookie(VisitorRegion::COOKIE, 'USD');
    }

    /** A cookie is whatever the client says it is, so it is validated. */
    public function test_a_nonsense_currency_is_refused(): void
    {
        $this->from(route('pricing'))
            ->post(route('currency'), ['currency' => 'DOGE'])
            ->assertRedirect(route('pricing'))
            ->assertCookieMissing(VisitorRegion::COOKIE);

        $this->withCookie(VisitorRegion::COOKIE, 'DOGE')
            ->get(route('pricing'))
            ->assertOk()
            ->assertDontSee('Your choice');
    }

    /** Every page carries the switch, so a wrong guess is always fixable. */
    public function test_the_switch_is_on_every_page(): void
    {
        foreach ($this->pages() as $name) {
            $this->get(route($name))->assertSee('Choose a currency', false);
        }
    }

    // ── A header is only trusted from a proxy we configured ──────────────

    /**
     * The gap this closes: a country header is set by an edge, but anybody
     * can send one. Trusting it unconditionally would let a visitor move
     * themselves to another continent to change the price.
     */
    public function test_a_country_header_from_nowhere_in_particular_is_ignored(): void
    {
        $this->withHeaders(['CF-IPCountry' => 'UG', 'Accept-Language' => 'en-GB'])
            ->get(route('pricing'))
            ->assertOk()
            // The header claimed Uganda; it was not trusted, so the language
            // hint decided instead.
            ->assertSee('outside East Africa');
    }

    public function test_the_same_header_is_trusted_behind_a_trusted_proxy(): void
    {
        \Illuminate\Http\Middleware\TrustProxies::at('*');

        $this->withHeaders([
            'CF-IPCountry' => 'UG',
            'Accept-Language' => 'en-GB',
            'X-Forwarded-For' => '102.134.0.1',
        ])->get(route('pricing'))->assertOk()->assertSee('in East Africa');
    }

    // ── Prices on the page match the plans in the database ───────────────

    public function test_the_page_quotes_every_active_plan(): void
    {
        $html = $this->withHeader('Accept-Language', 'en-UG')->get(route('pricing'))->getContent();

        foreach (Plan::where('is_active', true)->get() as $plan) {
            $this->assertStringContainsString($plan->name, $html);
            $this->assertStringContainsString(
                PlatformPrice::make($plan->price, 'UGX')->label(),
                $html,
                "{$plan->name} is not quoted at the price in the database",
            );
        }
    }

    public function test_a_sign_up_link_carries_the_plan_it_sat_under(): void
    {
        $plan = Plan::where('is_active', true)->firstOrFail();

        $this->get(route('pricing'))
            ->assertOk()
            ->assertSee(e(route('register', ['plan' => $plan->id])), false);
    }

    // ── Findable ─────────────────────────────────────────────────────────

    public function test_the_sitemap_lists_every_public_page_and_nothing_private(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk();

        $this->assertStringStartsWith('application/xml', (string) $response->headers->get('content-type'));

        $xml = $response->getContent();

        foreach ($this->pages() as $name) {
            $this->assertStringContainsString(route($name), $xml, "{$name} is missing from the sitemap");
        }

        // A sitemap that advertises the sign-in page is working against
        // itself, and one that advertises the demonstration door tells the
        // world a door exists.
        foreach ([route('admin.login'), route('register')] as $private) {
            $this->assertStringNotContainsString('<loc>'.$private.'</loc>', $xml);
        }
        $this->assertStringNotContainsString('test-login', $xml);
    }

    public function test_robots_keeps_crawlers_out_of_the_panel(): void
    {
        $robots = (string) file_get_contents(public_path('robots.txt'));

        foreach (['/admin', '/field', '/test-login'] as $path) {
            $this->assertStringContainsString('Disallow: '.$path, $robots);
        }

        $this->assertStringContainsString('Sitemap:', $robots);
    }

    public function test_the_page_carries_structured_data(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('application/ld+json', false)
            ->assertSee('SoftwareApplication', false)
            // The FAQ on the page is offered to a search engine as well.
            ->assertSee('FAQPage', false);
    }

    // ── The contact form ─────────────────────────────────────────────────

    public function test_an_enquiry_reaches_somebody(): void
    {
        Notification::fake();
        config()->set('mail.enquiries_to', 'sales@example.test');

        $this->post(route('contact.send'), [
            'name' => 'Sarah Nakato',
            'email' => 'sarah@clinic.test',
            'hospital' => 'St. Mary’s Clinic',
            'message' => 'We run a twelve-bed clinic and would like a walkthrough.',
        ])->assertRedirect(route('contact'))->assertSessionHas('sent');

        Notification::assertSentOnDemand(PublicEnquiryReceived::class);
    }

    public function test_an_incomplete_enquiry_is_refused_field_by_field(): void
    {
        Notification::fake();

        $this->from(route('contact'))
            ->post(route('contact.send'), ['name' => '', 'email' => 'not-an-email', 'message' => 'hi'])
            ->assertSessionHasErrors(['name', 'email', 'message']);

        Notification::assertNothingSent();
    }

    /** The honeypot: a field no person can see, so a filled one is not a person. */
    public function test_a_filled_honeypot_is_dropped(): void
    {
        Notification::fake();

        $this->from(route('contact'))
            ->post(route('contact.send'), [
                'name' => 'Definitely A Person',
                'email' => 'bot@example.test',
                'message' => 'Buy cheap watches from our website today.',
                'website' => 'http://spam.example',
            ])
            ->assertSessionHasErrors('website');

        Notification::assertNothingSent();
    }

    public function test_the_form_is_rate_limited(): void
    {
        Notification::fake();
        RateLimiter::clear('enquiry:127.0.0.1');

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('contact.send'), [
                'name' => 'Sarah', 'email' => 'sarah@clinic.test',
                'message' => 'A perfectly reasonable enquiry about your plans.',
            ]);
        }

        $this->from(route('contact'))
            ->post(route('contact.send'), [
                'name' => 'Sarah', 'email' => 'sarah@clinic.test',
                'message' => 'A perfectly reasonable enquiry about your plans.',
            ])
            ->assertSessionHasErrors('message');

        Notification::assertSentOnDemandTimes(PublicEnquiryReceived::class, 5);
    }

    public function test_the_thank_you_is_shown_after_a_redirect(): void
    {
        Notification::fake();
        RateLimiter::clear('enquiry:127.0.0.1');

        $this->post(route('contact.send'), [
            'name' => 'Sarah', 'email' => 'sarah@clinic.test',
            'message' => 'We would like to see the inpatient module in action.',
        ]);

        // Post/redirect/get: refreshing the page must not send it twice.
        $this->followingRedirects()
            ->post(route('contact.send'), [
                'name' => 'Sarah', 'email' => 'sarah@clinic.test',
                'message' => 'We would like to see the inpatient module in action.',
            ])
            ->assertOk()
            ->assertSee('your message is with us');
    }
}
