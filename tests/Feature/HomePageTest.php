<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\StandaloneProduct;
use App\Support\SiteShots;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The home page (roadmap 02 §4) and the rules every public page keeps:
 * real screenshots with their sizes, prices from the tables, the WhatsApp
 * line, no icon font, no em dashes, and a first load under 200 KB.
 */
class HomePageTest extends TestCase
{
    use RefreshDatabase;

    /** Every public page a visitor can land on. */
    private const PUBLIC_PAGES = [
        '/', '/features', '/pricing', '/security', '/contact', '/privacy', '/terms', '/own-your-copy',
        '/about', '/walkthrough', '/get-app', '/for/dental-clinics', '/for/private-clinics',
        '/for/maternity-centres', '/for/hospitals',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['support.whatsapp' => '256700111222']);
        Plan::factory()->create(['name' => 'Starter', 'slug' => 'starter', 'price' => '10000.00', 'is_active' => true]);
        Plan::factory()->create(['name' => 'Professional', 'slug' => 'professional', 'price' => '50000.00', 'is_active' => true, 'is_featured' => true]);
    }

    public function test_the_hero_says_what_it_is_and_offers_both_ways_in_and_a_person(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Run your clinic from one record', false)
            ->assertSee('Start free for 14 days')
            ->assertSee('Questions? WhatsApp us on +256 700 111 222')
            ->assertSee(route('go.whatsapp'), false)
            ->assertSee('<title>True-Doctor: clinic and hospital software that works offline</title>', false);
    }

    public function test_every_picture_is_a_real_screenshot_with_its_size_and_only_the_first_ones_load_eagerly(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        foreach (['dashboard', 'phone', 'reception', 'visit', 'lab', 'dental', 'ward', 'cashier', 'closing'] as $name) {
            $shot = SiteShots::get($name);
            $this->assertNotNull($shot, "{$name} is missing from public/images/site/shots/manifest.json");
            $this->assertFileExists(public_path('images/site/shots/'.$shot['file']));
            $this->assertStringContainsString('images/site/shots/'.$shot['file'], $html);
            $this->assertStringContainsString('width="'.$shot['w'].'" height="'.$shot['h'].'"', $html, "{$name} has no size, so the page would jump");
        }

        preg_match_all('/<img [^>]*class="shot-img"[^>]*>/', $html, $images);
        $eager = array_filter($images[0], fn ($img) => ! str_contains($img, 'loading="lazy"'));
        $this->assertCount(2, $eager, 'only the hero laptop and phone load before scrolling');
        $this->assertStringContainsString('fetchpriority="high"', implode('', $eager));
        foreach ($images[0] as $img) {
            $this->assertMatchesRegularExpression('/ alt="[^"]{12,}"/', $img, 'every screenshot says what it shows');
        }
    }

    public function test_prices_come_from_the_tables_with_the_price_of_a_day(): void
    {
        StandaloneProduct::query()->update(['is_active' => false]); // only this test's product on sale
        StandaloneProduct::query()->create(['name' => 'Clinic copy', 'slug' => 'clinic', 'price_ugx' => '3000000.00', 'updates_months' => 12, 'is_active' => true]);

        $this->withCookie('td_currency', 'UGX')->get('/')
            ->assertSee('USh 50,000')->assertSee('About USh 1,700 a day')
            ->assertSee('USh 330 a day', false)
            ->assertSee('USh 3,000,000')->assertSee('12 months of updates');
    }

    public function test_patient_messages_are_not_promised_until_they_go_out(): void
    {
        $this->get('/')->assertDontSee('Patients hear from you');

        config(['landing.home.patient_messages' => true]);
        $this->get('/')->assertSee('Patients hear from you');
    }

    public function test_the_practices_and_the_honest_offline_lists_are_there(): void
    {
        $response = $this->get('/');

        foreach (array_keys(config('landing.practices')) as $slug) {
            $response->assertSee(route('for', $slug), false);
        }
        $response->assertSee('Works with no signal')->assertSee('Waits for the network')
            ->assertSee('Do you issue EFRIS e-receipts?')->assertSee('Not yet.');
    }

    public function test_no_public_page_loads_an_icon_font_or_prints_an_em_dash(): void
    {
        foreach (self::PUBLIC_PAGES as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $visible = preg_replace('#<script\b.*?</script>#s', '', $html);

            $this->assertDoesNotMatchRegularExpression('/font-?awesome|fa\/css|all\.min\.css|class="fa[srb]? fa-/i', $html, "{$path} uses Font Awesome");
            $this->assertStringNotContainsString('fonts.googleapis.com', $html, "{$path} fetches a font from Google");
            $this->assertStringNotContainsString('—', $visible, "{$path} shows an em dash");
        }
    }

    /**
     * Roadmap 02 §3: at most 200 KB on first load. Counted as it travels: the
     * HTML, stylesheet and script gzipped (the server compresses them), the
     * font and the pictures that load before any scrolling at full size.
     */
    public function test_the_home_page_first_load_stays_under_200_kb(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $bytes = strlen((string) gzencode($html, 6));
        $counted = ['html' => $bytes];

        preg_match_all('#(?:href|src)="[^"]*?/(build/assets/[^"]+\.(?:css|js))"#', $html, $assets);
        foreach (array_unique($assets[1]) as $asset) {
            $size = strlen((string) gzencode((string) file_get_contents(public_path($asset)), 6));
            $counted[$asset] = $size;
            $bytes += $size;
        }

        preg_match_all('#<link rel="preload" href="[^"]*?/(fonts/[^"]+)"#', $html, $fonts);
        foreach ($fonts[1] as $font) {
            $counted[$font] = filesize(public_path($font));
            $bytes += $counted[$font];
        }

        preg_match_all('/<img [^>]*>/', $html, $images);
        foreach ($images[0] as $img) {
            if (str_contains($img, 'loading="lazy"') || ! preg_match('#src="[^"]*?/(images/[^"]+)"#', $img, $m)) {
                continue;
            }
            $counted[$m[1]] = filesize(public_path($m[1]));
            $bytes += $counted[$m[1]];
        }

        $this->assertLessThanOrEqual(200 * 1024, $bytes, 'first load is '.round($bytes / 1024).' KB: '.json_encode(array_map(fn ($b) => round($b / 1024).' KB', $counted)));
    }
}
