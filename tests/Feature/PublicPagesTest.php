<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The pages that are content in config/landing.php, and the technical SEO around them. */
class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['support.whatsapp' => '256700111222']);
    }

    /** @return array<string,string> path => page key */
    private function contentPages(): array
    {
        $pages = [];
        foreach (array_keys(config('landing.practices')) as $slug) {
            $pages["/for/{$slug}"] = $slug;
        }

        return $pages + ['/walkthrough' => 'walkthrough', '/get-app' => 'get-app', '/about' => 'about', '/own-your-copy' => 'own-your-copy'];
    }

    public function test_every_content_page_stands_on_its_own(): void
    {
        $titles = [];
        $descriptions = [];

        foreach (array_keys($this->contentPages()) as $path) {
            $html = $this->get($path)->assertOk()->getContent();

            preg_match('/<title>(.*?)<\/title>/s', $html, $t);
            preg_match('/<meta name="description" content="([^"]*)"/', $html, $d);
            $title = html_entity_decode(trim($t[1] ?? ''));
            $description = html_entity_decode($d[1] ?? '');

            $this->assertNotSame('', $title, "{$path} has a title");
            $this->assertLessThanOrEqual(60, mb_strlen($title), "{$path} title: {$title}");
            $this->assertNotSame('', $description, "{$path} has a description");
            $this->assertLessThanOrEqual(155, mb_strlen($description), "{$path} description is too long");
            $this->assertStringContainsString('<link rel="canonical" href="'.url($path).'"', $html);
            $this->assertStringContainsString('property="og:image"', $html);
            $this->assertStringContainsString('name="twitter:title"', $html);
            $this->assertStringContainsString('https://wa.me/256700111222', $html, "{$path} offers WhatsApp");
            $this->assertStringNotContainsStringIgnoringCase('lorem', $html);
            $this->assertStringNotContainsString('TODO', $html);
            $this->assertStringNotContainsString('—', strip_tags(preg_replace('/<script.*?<\/script>/s', '', $html) ?? ''), "{$path}: no em dashes in the words");

            preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $ld);
            foreach ($ld[1] as $json) {
                $this->assertNotNull(json_decode(trim($json), true), "{$path} has invalid JSON-LD");
            }
            $this->assertStringContainsString('"BreadcrumbList"', $html, "{$path} has breadcrumbs");

            $titles[] = $title;
            $descriptions[] = $description;
        }

        $this->assertSame(count($titles), count(array_unique($titles)), 'every title is unique');
        $this->assertSame(count($descriptions), count(array_unique($descriptions)), 'every description is unique');
    }

    public function test_an_unknown_practice_is_a_404(): void
    {
        $this->get('/for/vets')->assertNotFound();
    }

    public function test_the_sitemap_is_an_index_and_lists_every_public_page_with_lastmod(): void
    {
        $this->get('/sitemap.xml')->assertOk()->assertSee('<sitemapindex', false)->assertSee(route('sitemap.pages'), false);

        $xml = $this->get('/sitemap-pages.xml')->assertOk()->getContent();
        foreach (array_keys($this->contentPages()) as $path) {
            $this->assertStringContainsString('<loc>'.url($path).'</loc>', $xml, "{$path} is in the sitemap");
        }
        $this->assertStringContainsString('<lastmod>2026-10-17</lastmod>', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    public function test_robots_names_the_sitemap_and_keeps_private_pages_out(): void
    {
        $robots = $this->get('/robots.txt')->assertOk()->getContent();

        $this->assertStringContainsString('Sitemap: '.route('sitemap'), $robots);
        foreach (['/admin', '/super', '/my-licence', '/s/', '/field', '/test-login', '/install.php'] as $private) {
            $this->assertStringContainsString("Disallow: {$private}", $robots);
        }
    }

    public function test_sign_up_and_sign_in_are_never_indexed(): void
    {
        $this->get('/register')->assertSee('<meta name="robots" content="noindex, nofollow">', false)->assertSee('Start your free 14-day trial', false);
        $this->get('/admin/login')->assertSee('<meta name="robots" content="noindex, nofollow">', false);
        $this->get('/')->assertDontSee('content="noindex', false);
    }

    public function test_the_old_standalone_address_moves_permanently(): void
    {
        $this->get('/standalone')->assertRedirect('/own-your-copy')->assertStatus(301);
    }

    public function test_the_demo_door_offers_the_roles_and_walks_a_visitor_in(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->seed(RbacSeeder::class);
        $this->seed(PlanSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->get('/demo')->assertOk()->assertSee('Walk in as Doctor', false);

        $this->post('/demo/doctor')->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated();
        $this->assertSame('doctor', auth()->user()->role);

        $this->post('/demo/receptionist')->assertRedirect(route('admin.dashboard'));
        $this->assertSame('receptionist', auth()->user()->role, 'a demo visitor changes role');
    }

    public function test_a_real_person_signed_in_keeps_their_session(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->seed(RbacSeeder::class);
        $this->seed(PlanSeeder::class);
        $this->seed(DemoSeeder::class);
        $owner = User::factory()->create(['hospital_id' => Hospital::factory()->create()->id, 'role' => 'hospital_admin']);

        $this->actingAs($owner)->post('/demo/doctor')->assertRedirect(route('admin.dashboard'));
        $this->assertSame($owner->id, auth()->id());
    }

    public function test_a_role_the_demo_does_not_hold_goes_back_to_the_door(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->seed(RbacSeeder::class);
        $this->seed(PlanSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->post('/demo/astronaut')->assertRedirect(route('demo'));
        $this->assertGuest();
    }
}
