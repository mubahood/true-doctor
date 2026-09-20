<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\User;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The sidebar is the map of the system, so it is held to map rules rather than
 * to a snapshot of its own markup:
 *
 *  1. Nothing it offers is a dead end — every link opens, for every role.
 *  2. The label on the link is the title of the page it opens.
 *  3. It runs in the order the day runs: Dashboard → Patient care →
 *     Billing & finance → Administration, and inside Patient care it follows
 *     the visit spine (docs/visits.md).
 *  4. Today's work and once-a-year setup never share a group.
 *  5. Nothing is orphaned: a module page with no way in is a bug.
 *
 * Together these are what stop the menu drifting back into ten flat groups
 * where "Lab orders" and "Test catalogue" look like the same kind of thing.
 */
class SidebarMenuTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pages that deliberately have no menu entry, and why. Anything else
     * reachable must be findable — see test_no_module_page_is_orphaned.
     */
    private const NOT_IN_MENU = [
        'admin.notifications.index' => 'reached from the bell in the header — a personal inbox, not a module',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    // ── 1. No dead ends ──────────────────────────────────────────────────

    /**
     * Every role, every link. A menu entry that 403s teaches the user that the
     * menu lies, and from then on they stop trusting all of it.
     */
    public function test_every_link_the_menu_offers_opens_for_every_role(): void
    {
        foreach (array_keys(RbacSeeder::MATRIX) as $role) {
            $user = $this->userFor($role);
            $links = $this->menuLinks($user);

            $this->assertNotEmpty($links, "{$role} was offered an empty menu");

            foreach ($links as $link) {
                $this->actingAs($user)->get($link)
                    ->assertOk("{$role} is offered {$link} but cannot open it");
            }
        }
    }

    // ── 2. The label is the page title ───────────────────────────────────

    /**
     * Click "Lab tests", land on a page headed "Lab tests". When the two drift
     * apart the user cannot tell whether they arrived where they meant to.
     */
    public function test_every_label_matches_the_title_of_the_page_it_opens(): void
    {
        // Both roles: the super-admin's Platform section is invisible to a
        // hospital admin, and it holds the very page this rule was written for
        // (Settings, retitled "Site settings" so it stops colliding with
        // "Billing settings" one row below it).
        foreach (['hospital_admin', 'super_admin'] as $role) {
            $user = $this->userFor($role);

            foreach ($this->menuEntries($user) as $label => $href) {
                $html = $this->actingAs($user)->get($href)->getContent();
                preg_match('#<title>(.*?)</title>#s', (string) $html, $m);

                $this->assertNotEmpty($m, "{$href} rendered no <title>");
                $this->assertSame(
                    $label,
                    trim(explode('·', html_entity_decode($m[1]))[0]),
                    "the menu calls {$href} \"{$label}\" but the page calls itself something else",
                );
            }
        }
    }

    /** Nobody should have to open a folder to find one thing. */
    public function test_a_group_that_gates_down_to_one_child_renders_as_that_child(): void
    {
        // A lab technician has lab.view but not radiology.view, so Diagnostics
        // holds only "Lab orders" for them: it renders flat, with no expander.
        $sidebar = $this->sidebar($this->userFor('lab_technician'));

        $this->assertStringNotContainsString('Diagnostics', $sidebar);
        $this->assertStringContainsString('href="'.route('admin.lab-orders.index').'"', $sidebar);
        $this->assertStringNotContainsString('navsub-diagnostics', $sidebar);

        // …and a doctor, who has both, still gets the group.
        $this->assertStringContainsString('Diagnostics', $this->sidebar($this->userFor('doctor')));
    }

    // ── 3. The order is the day ──────────────────────────────────────────

    public function test_the_sections_run_in_the_order_the_day_runs(): void
    {
        $sidebar = $this->sidebar($this->userFor('hospital_admin'));

        $this->assertOrder(
            ['Patient care', 'Billing &amp; finance', 'Administration'],
            $sidebar,
        );
    }

    /**
     * Visits first — everything a patient does hangs off one, and that page
     * opens a visit for a walk-in, registering them on the spot. Then the
     * diary, the orders raised inside a visit, dispensing, admissions. The
     * patient register comes LAST: it is reference data, opened when someone
     * new arrives or a detail is wrong, not every time you see them.
     */
    public function test_patient_care_is_ordered_by_how_much_of_the_day_each_thing_takes(): void
    {
        $sidebar = $this->sidebar($this->userFor('hospital_admin'));

        $this->assertOrder(
            ['Visits', 'Scheduling', 'Diagnostics', 'Pharmacy', 'Inpatient', 'Patients'],
            $this->between($sidebar, 'Patient care', 'Billing &amp; finance'),
        );
    }

    /** The register must never climb back above the work it supports. */
    public function test_the_patient_register_is_the_last_thing_in_patient_care(): void
    {
        foreach (['hospital_admin', 'doctor', 'nurse', 'receptionist'] as $role) {
            $care = $this->section($this->sidebar($this->userFor($role)), 'care');
            $labels = array_keys($this->entriesIn($care));

            $this->assertSame('Patients', end($labels), "{$role}'s Patient care does not end at the register");
            $this->assertSame('Visits', $labels[0], "{$role}'s Patient care does not start at the visit");
        }
    }

    /**
     * The dashboard has no row of its own: the brand mark at the top of the
     * sidebar is that link, and one destination does not need two controls
     * stacked on each other. So the menu opens on the work — but the way home
     * must still be there, for every role, or removing the row orphaned it.
     */
    public function test_the_brand_is_the_way_to_the_dashboard_and_the_menu_opens_on_the_work(): void
    {
        foreach (['hospital_admin', 'doctor', 'nurse', 'receptionist', 'pharmacist'] as $role) {
            $sidebar = $this->sidebar($this->userFor($role));

            $this->assertStringNotContainsString(
                '>Dashboard<',
                $sidebar,
                "{$role} is offered a Dashboard row as well as the brand link",
            );
            $this->assertMatchesRegularExpression(
                '#<a[^>]+href="'.preg_quote(route('admin.dashboard'), '#').'"[^>]*>.*?logo-icon#s',
                $sidebar,
                "{$role} has no way back to the dashboard",
            );
            $this->assertSame(
                'Patient care',
                $this->firstSectionLabel($sidebar),
                "{$role}'s menu does not open on Patient care",
            );
        }
    }

    // ── 4. Work and setup are separate ───────────────────────────────────

    /**
     * "Lab orders" changes by the hour; "Lab tests" is edited twice a year.
     * They used to sit side by side looking identical. Catalogues, lists and
     * settings live under Administration; the care sections hold only work.
     */
    public function test_no_catalogue_or_settings_page_sits_among_the_daily_work(): void
    {
        $sidebar = $this->sidebar($this->userFor('hospital_admin'));
        $work = $this->between($sidebar, 'Patient care', 'Administration');

        $setupPages = [
            'admin.lab-tests.index', 'admin.radiology-studies.index',
            'admin.stock-categories.index', 'admin.services.index',
            'admin.insurance-providers.index', 'admin.wards.index', 'admin.beds.index',
            'admin.departments.index', 'admin.rooms.index', 'admin.staff.index',
            'admin.users.index', 'admin.settings.billing', 'admin.onboarding',
        ];

        foreach ($setupPages as $route) {
            $this->assertStringNotContainsString(
                'href="'.route($route).'"',
                $work,
                "{$route} is setup, not daily work — it belongs under Administration",
            );
        }
    }

    /** …and conversely, the daily queues are never buried in Administration. */
    public function test_the_daily_queues_are_not_buried_in_administration(): void
    {
        $sidebar = $this->sidebar($this->userFor('hospital_admin'));
        $config = $this->after($sidebar, 'Administration');

        foreach (['admin.visits.index', 'admin.lab-orders.index', 'admin.invoices.index', 'admin.appointments.queue'] as $route) {
            $this->assertStringNotContainsString('href="'.route($route).'"', $config, "{$route} is daily work");
        }
    }

    // ── 5. Nothing is orphaned ───────────────────────────────────────────

    /**
     * The other half of "no dead ends": a page a role may open must be in that
     * role's menu. Checking only the hospital admin proves nothing — they hold
     * every permission, so a gate that is too NARROW hides a page from everyone
     * else and still looks perfect from the top. Every role, every page.
     *
     * Pages with no menu entry by design are listed in NOT_IN_MENU with a reason.
     */
    public function test_no_page_a_role_may_open_is_missing_from_their_menu(): void
    {
        foreach (array_keys(RbacSeeder::MATRIX) as $role) {
            $user = $this->userFor($role);
            $offered = array_values($this->menuEntries($user));

            foreach ($this->indexPages() as $name => $url) {
                // Not theirs to open, so not theirs to be offered.
                if (! $this->actingAs($user)->get($url)->isOk()) {
                    continue;
                }

                $this->assertContains(
                    $url,
                    $offered,
                    "{$role} can open {$name} but their menu never offers it",
                );
            }
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Every module landing page: a named, parameterless *.index route under
     * admin or super. Detail pages are reached from their index, not the menu.
     *
     * @return array<string,string> route name => url
     */
    private function indexPages(): array
    {
        $pages = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if (! $name || ! Str::endsWith($name, '.index')) {
                continue;
            }
            if (! Str::startsWith($name, ['admin.', 'super.'])) {
                continue;
            }
            if (str_contains((string) $route->uri(), '{') || isset(self::NOT_IN_MENU[$name])) {
                continue;
            }

            $pages[$name] = route($name);
        }

        return $pages;
    }

    private function userFor(string $role): User
    {
        $hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($hospital->id);

        $user = User::factory()->create([
            'hospital_id' => $role === 'super_admin' ? null : $hospital->id,
            'role' => $role,
            'is_admin' => $role === 'super_admin',
        ]);
        $user->syncSpatieRole();

        return $user;
    }

    /** The rendered sidebar only — the page body links to plenty the menu does not. */
    private function sidebar(User $user, string $url = '/admin'): string
    {
        $html = $this->actingAs($user)->get($url)->getContent();
        preg_match('#<aside class="tb-sidebar".*?</aside>#s', (string) $html, $m);

        $this->assertNotEmpty($m, 'the sidebar did not render');

        return $m[0];
    }

    /** @return list<string> every href the menu offers this user */
    private function menuLinks(User $user): array
    {
        return array_values(array_unique(array_values($this->menuEntries($user))));
    }

    /** @return array<string,string> label => href */
    private function menuEntries(User $user): array
    {
        return $this->entriesIn($this->sidebar($user));
    }

    /** @return array<string,string> label => href, in the order they appear */
    private function entriesIn(string $sidebar): array
    {
        preg_match_all('#<a[^>]+href="([^"]+)"[^>]*>(.*?)</a>#s', $sidebar, $m, PREG_SET_ORDER);

        $entries = [];
        foreach ($m as [, $href, $inner]) {
            $label = trim(html_entity_decode(strip_tags($inner)));
            // The brand link carries the hospital name, not a menu label.
            if ($label === '' || str_contains($inner, 'logo-icon')) {
                continue;
            }
            $entries[$label] = $href;
        }

        return $entries;
    }

    /**
     * One section's markup, by its key — each renders as its own .tb-nav-sec
     * block. Exact, unlike slicing on a caption, which runs into whatever
     * section follows when a role cannot see the next one.
     */
    private function firstSectionLabel(string $sidebar): string
    {
        preg_match('#<h2 class="tb-nav-section"[^>]*>(.*?)</h2>#s', $sidebar, $m);

        return trim(html_entity_decode($m[1] ?? ''));
    }

    private function section(string $sidebar, string $key): string
    {
        foreach (explode('<div class="tb-nav-sec">', $sidebar) as $block) {
            if (str_contains($block, 'id="navsec-'.$key.'"')) {
                return $block;
            }
        }

        $this->fail("the \"{$key}\" section did not render");
    }

    private function between(string $haystack, string $from, string $to): string
    {
        return Str::before(Str::after($haystack, $from), $to);
    }

    private function after(string $haystack, string $from): string
    {
        return Str::after($haystack, $from);
    }

    /** @param list<string> $needles */
    private function assertOrder(array $needles, string $haystack): void
    {
        $at = -1;
        foreach ($needles as $needle) {
            $found = strpos($haystack, $needle);
            $this->assertNotFalse($found, "\"{$needle}\" is missing from the menu");
            $this->assertGreaterThan($at, $found, "\"{$needle}\" comes out of order");
            $at = $found;
        }
    }
}
