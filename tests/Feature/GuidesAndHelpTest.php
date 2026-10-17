<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\User;
use App\Support\CurrentHospital;
use App\Support\HelpGuides;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The first six guides on the public site (roadmap 02 §7) and the "Help" link
 * on every back-office screen (06 §4): one mapping from screen to guide.
 */
class GuidesAndHelpTest extends TestCase
{
    use RefreshDatabase;

    private const SIX = [
        'register-a-private-clinic-in-uganda',
        'hmis-105-explained',
        'running-a-clinic-with-no-internet',
        'setting-clinic-prices-that-cover-costs',
        'getting-paid-by-insurers-in-uganda',
        'stopping-money-leaks-at-the-cashier',
    ];

    public function test_the_first_six_guides_are_the_roadmaps_first_six(): void
    {
        $this->assertSame(self::SIX, array_keys(HelpGuides::all()));
    }

    public function test_the_hub_lists_every_guide(): void
    {
        $page = $this->get('/guides')->assertOk()->assertSee('Guides for running a clinic');
        foreach (HelpGuides::all() as $slug => $guide) {
            $page->assertSee(route('guides.show', $slug), false)->assertSee($guide['h1']);
        }
    }

    public function test_each_guide_is_dated_signed_sourced_and_ends_with_one_product_link(): void
    {
        foreach (HelpGuides::all() as $slug => $guide) {
            $html = $this->get('/guides/'.$slug)->assertOk()
                ->assertSee('<h1>'.e($guide['h1']).'</h1>', false)
                ->assertSee('Muhindo Mubaraka')
                ->assertSee('MSc Computer Science')
                ->assertSee('Updated')
                ->assertSee('Sources')
                ->assertSee('"@type":"Article"', false)
                ->getContent();

            [, $route, $params] = $guide['product'];
            $this->assertStringContainsString('href="'.route($route, $params).'"', $html, $slug);
            $this->assertNotEmpty($guide['sources'], $slug);
            foreach ($guide['sources'] as [, $url]) {
                $this->assertStringStartsWith('https://', $url);
            }
        }
    }

    public function test_the_words_carry_no_em_dashes(): void
    {
        $text = json_encode(config('guides'), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('—', (string) $text);
        $this->assertStringNotContainsString('–', (string) $text);
    }

    public function test_real_screenshots_where_a_guide_has_one(): void
    {
        $this->get('/guides/stopping-money-leaks-at-the-cashier')->assertOk()->assertSee('closing', false)->assertSee('page-shot', false);
        $this->get('/guides/hmis-105-explained')->assertOk()->assertDontSee('page-shot', false);
    }

    public function test_an_unknown_guide_is_a_404(): void
    {
        $this->get('/guides/how-to-fly')->assertNotFound();
    }

    public function test_the_guides_are_in_the_sitemap_and_the_footer(): void
    {
        $xml = $this->get('/sitemap-pages.xml')->assertOk()->getContent();
        $this->assertStringContainsString(route('guides.index'), $xml);
        foreach (self::SIX as $slug) {
            $this->assertStringContainsString(route('guides.show', $slug), $xml);
        }

        $this->get('/about')->assertOk()->assertSee(route('guides.index'), false);
    }

    // ── The one mapping ─────────────────────────────────────────────────

    public function test_every_mapping_names_a_guide_and_matches_a_real_screen(): void
    {
        $names = array_keys(Route::getRoutes()->getRoutesByName());
        foreach (HelpGuides::ROUTES as $pattern => $slug) {
            $this->assertNotNull(HelpGuides::find($slug), "{$pattern} points at a missing guide");
            $matches = array_filter($names, fn ($n) => str_ends_with($pattern, '.') ? str_starts_with($n, $pattern) : $n === $pattern);
            $this->assertNotEmpty($matches, "{$pattern} matches no screen");
        }
    }

    public function test_screens_map_to_their_guide_and_the_rest_to_the_hub(): void
    {
        $this->assertSame('stopping-money-leaks-at-the-cashier', HelpGuides::forRoute('admin.invoices.show'));
        $this->assertSame('hmis-105-explained', HelpGuides::forRoute('admin.reports.hmis'));
        $this->assertSame('getting-paid-by-insurers-in-uganda', HelpGuides::forRoute('admin.insurance-claims.index'));
        $this->assertSame('setting-clinic-prices-that-cover-costs', HelpGuides::forRoute('admin.settings.billing'));
        $this->assertSame('register-a-private-clinic-in-uganda', HelpGuides::forRoute('admin.settings.hospital'));
        $this->assertSame('running-a-clinic-with-no-internet', HelpGuides::forRoute('admin.offline.readiness'));
        $this->assertNull(HelpGuides::forRoute('admin.settings.billing-extra'), 'an exact name is not a prefix');
        $this->assertNull(HelpGuides::forRoute('admin.patients.index'));

        $this->assertSame(route('guides.index'), HelpGuides::linkFor('admin.patients.index')['url']);
        $this->assertSame(route('guides.show', 'hmis-105-explained'), HelpGuides::linkFor('admin.reports.index')['url']);
    }

    public function test_a_hospitals_own_copy_links_to_the_guides_online(): void
    {
        config(['edition.name' => 'standalone', 'guides.site_url' => 'https://true-doctor.online']);

        $this->assertSame('https://true-doctor.online/guides/hmis-105-explained', HelpGuides::url('hmis-105-explained'));
        $this->assertSame('https://true-doctor.online/guides', HelpGuides::url());
    }

    // ── On the screens ──────────────────────────────────────────────────

    private function signIn(string $role): User
    {
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $h = Hospital::factory()->create();
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    public function test_a_page_header_carries_help_for_its_screen(): void
    {
        $this->signIn('hospital_admin');

        $this->get(route('admin.settings.hospital'))->assertOk()
            ->assertSee('class="tb-help-link"', false)
            ->assertSee(route('guides.show', 'register-a-private-clinic-in-uganda'), false);
    }

    public function test_every_screen_has_the_guide_in_the_help_menu(): void
    {
        $this->signIn('hospital_admin');

        // A list page with no visible header still has the topbar's menu.
        $this->get(route('admin.invoices.index'))->assertOk()
            ->assertSee('Guide for this screen')
            ->assertSee(route('guides.show', 'stopping-money-leaks-at-the-cashier'), false);

        $this->get(route('admin.patients.index'))->assertOk()
            ->assertSee(route('guides.index'), false);
    }
}
