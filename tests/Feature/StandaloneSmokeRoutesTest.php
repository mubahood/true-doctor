<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\CurrentHospital;
use App\Support\Navigation;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\Route;

/**
 * Rule R2 of docs/proposals/standalone-edition.md: everything else stays the
 * same. The whole back office is walked again as a standalone installation —
 * the demo data trimmed to its single organisation, the edition switched,
 * the installation marked as installed — and every page a hospital uses must
 * render exactly as it does online. The pages that belong only to the online
 * service must be gone, and nothing in any role's menu may point at one.
 */
class StandaloneSmokeRoutesTest extends SmokeRoutesTest
{
    private string $marker;

    protected function setUp(): void
    {
        // One organisation: the demo's second tenant is the online service's.
        $this->afterApplicationCreated(fn () => config()->set('demo.second_tenant', false));

        parent::setUp();

        $this->marker = storage_path('framework/testing/installed-smoke-'.bin2hex(random_bytes(4)).'.json');
        file_put_contents($this->marker, json_encode(['edition' => 'standalone', 'version' => '1.0.0']));
        config()->set('edition.name', 'standalone');
        config()->set('edition.installed_path', $this->marker);
        $this->hospital->forceFill(['standalone_slot' => 1])->save();
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    public function test_every_admin_page_renders_for_a_hospital_admin(): void
    {
        $admin = User::where('email', 'admin.a@test.com')->firstOrFail();
        $this->actingAs($admin);
        app(CurrentHospital::class)->set($this->hospital->id);

        $failures = [];
        foreach ($this->pageRoutes('admin.') as $route) {
            $parameters = $this->parametersFor($route);
            if ($parameters === null) {
                continue;
            }
            $status = $this->get(route($route->getName(), $parameters))->getStatusCode();
            $onlineOnly = in_array('edition:saas', $route->gatherMiddleware(), true);

            if ($onlineOnly ? $status !== 404 : ($status >= 500 || $status === 404)) {
                $failures[] = $route->getName().' => '.$status.($onlineOnly ? ' (should be absent)' : '');
            }
        }

        $this->assertSame([], $failures, "Standalone pages that did not behave:\n".implode("\n", $failures));
        $this->get(route('admin.system.index'))->assertOk()->assertSee('Licence &amp; updates', false);
    }

    public function test_every_super_admin_page_renders(): void
    {
        // There is no platform on a standalone installation: every one of its
        // pages is absent, even to the hospital's own administrator.
        $this->actingAs(User::where('email', 'admin.a@test.com')->firstOrFail());

        foreach ($this->pageRoutes('super.') as $route) {
            $this->get('/'.ltrim(preg_replace('/\{[^}]+\}/', '1', $route->uri()), '/'))
                ->assertNotFound();
        }
    }

    public function test_public_and_auth_pages_render(): void
    {
        $this->get('/')->assertRedirect(route('admin.login'));
        $this->get(route('admin.login'))->assertOk()->assertDontSee('Create your hospital');

        foreach (['/features', '/pricing', '/register', '/standalone', '/my-licence', '/contact', '/sitemap.xml'] as $online) {
            $this->get($online)->assertNotFound();
        }
    }

    public function test_every_role_has_a_working_menu_with_nothing_from_the_online_service(): void
    {
        $online = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteInstance $r) => in_array('edition:saas', $r->gatherMiddleware(), true))
            ->map(fn (RouteInstance $r) => $r->getName())->filter()->values()->all();

        foreach (['hospital_admin', 'doctor', 'nurse', 'receptionist', 'pharmacist', 'lab_technician', 'radiologist', 'accountant', 'records_officer'] as $role) {
            $user = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => $role]);
            $user->syncSpatieRole();
            $this->actingAs($user);

            $entries = collect(Navigation::for($user, false)['sections'])
                ->flatMap(fn ($s) => $s['entries'])
                ->flatMap(fn ($e) => $e['type'] === 'group' ? $e['items'] : [$e]);

            $this->assertNotEmpty($entries, "{$role} has an empty menu");
            foreach ($entries as $entry) {
                $this->assertNotContains($entry['route'], $online, "{$role} is offered {$entry['route']}, which only exists online");

                $response = $this->get(route($entry['route']));
                $this->assertSame(200, $response->getStatusCode(), "{$role} is offered {$entry['route']} but cannot open it");

                preg_match('#<title>(.*?)</title>#s', (string) $response->getContent(), $m);
                $this->assertSame($entry['label'], trim(explode('·', html_entity_decode($m[1] ?? ''))[0]), "{$entry['route']} is not titled \"{$entry['label']}\"");
            }
        }
    }

    public function test_the_api_says_which_edition_it_is_and_carries_no_subscription(): void
    {
        $admin = User::where('email', 'admin.a@test.com')->firstOrFail();
        $token = $admin->createToken('smoke')->plainTextToken;

        $meta = $this->withToken($token)->getJson('/api/v1/meta')->assertOk()->json('data');

        $this->assertSame('standalone', $meta['edition']);
        $this->assertNull($meta['subscription']);
    }
}
