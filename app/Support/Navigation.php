<?php

namespace App\Support;

use App\Http\Middleware\RequireOnboarding;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The back-office menu — one definition for every client.
 *
 * The web sidebar (resources/views/partials/admin-nav.blade.php) renders it,
 * and the API publishes it (GET /api/v1/meta) so the mobile and desktop app
 * shows the same destinations, in the same order, under the same names, to
 * the same people. It used to live inline in the Blade partial, where a second
 * client could only have copied it — and a copy drifts.
 *
 * It is ordered the way a day runs, not the way the codebase is filed:
 *
 *   PATIENT CARE  →  BILLING & FINANCE  →  ADMINISTRATION
 *
 * The dashboard is deliberately NOT a row here: the brand mark at the top of
 * the sidebar already goes there, and one destination does not need two
 * controls stacked on top of each other.
 *
 * Inside PATIENT CARE, by how much of the day each thing takes. VISITS leads:
 * everything a patient does hangs off a visit (docs/visits.md), and that page
 * opens one for a booked patient or a walk-in, registering them on the spot.
 * Then the diary, the orders raised inside a visit, dispensing, admissions —
 * and the patient REGISTER last, because you open it when someone new arrives
 * or a detail is wrong, not every time you see them.
 *
 * Two rules keep it from drifting back into a junk drawer:
 *
 *  1. WORK AND SETUP ARE NOT MIXED. "Lab orders" is a queue that changes by
 *     the hour; "Lab tests" is a list edited twice a year. Every catalogue,
 *     every list, every settings page lives under ADMINISTRATION, so the care
 *     sections contain only today's work.
 *  2. THE MENU LABEL IS THE PAGE TITLE. Click "Lab tests", land on a page
 *     headed "Lab tests". `SidebarMenuTest` walks every entry and asserts it.
 *
 * Every `can` mirrors the destination's OWN authorisation (its Policy::viewAny
 * or its abort_unless), so the menu is an exact map of what this person may
 * open — a wider menu would offer 403s, a narrower one would hide pages they
 * are allowed to use.
 *
 * Gates: null = always; 'super' = super-admin; string = permission;
 * array = "any of these permissions".
 */
final class Navigation
{
    /** The wizard's pages, in the order it asks for them (setup mode). */
    private const SETUP_ORDER = [
        'admin.onboarding',                                                     // the wizard itself
        'admin.settings.billing', 'admin.departments.index',                    // required steps
        'admin.services.index', 'admin.users.index', 'admin.offline-devices.index',
        'admin.rooms.index', 'admin.wards.index', 'admin.beds.index',           // recommended steps
        'admin.lab-tests.index', 'admin.radiology-studies.index', 'admin.stock-categories.index',
        'admin.subscription.index',                                             // always reachable
    ];

    /** @return list<array<string,mixed>> the whole menu, before anybody's permissions */
    public static function sections(): array
    {
        return [
            // ── The SaaS operator's own desk. First, because for them it is the job. ──
            ['key' => 'saas', 'label' => 'Platform', 'gate' => 'super', 'entries' => [
                ['key' => 'platform', 'label' => 'SaaS central', 'icon' => 'fa-shield-halved', 'items' => [
                    ['label' => 'Hospitals', 'icon' => 'fa-hospital', 'route' => 'super.hospitals.index', 'match' => ['super.hospitals.*']],
                    ['label' => 'Plans', 'icon' => 'fa-layer-group', 'route' => 'super.plans.index', 'match' => ['super.plans.*']],
                    ['label' => 'Subscriptions', 'icon' => 'fa-file-invoice-dollar', 'route' => 'super.subscriptions.index', 'match' => ['super.subscriptions.*']],
                    ['label' => 'Traffic & campaigns', 'icon' => 'fa-chart-line', 'route' => 'super.traffic.index', 'match' => ['super.traffic.*']],
                    // Platform-global config (site name, contacts) — a SaaS page, not a tenant one.
                    ['label' => 'Site settings', 'icon' => 'fa-sliders', 'route' => 'admin.settings.index', 'match' => ['admin.settings.index']],
                ]],
            ]],

            // ── The working day, most of it first. ────────────────────────────────
            ['key' => 'care', 'label' => 'Patient care', 'entries' => [
                // The spine, and the way in: everything a patient does hangs off a
                // visit, and this page opens one — for a registered patient or for
                // someone walking in for the first time (VisitService::intake).
                ['label' => 'Visits', 'icon' => 'fa-stethoscope', 'route' => 'admin.visits.index', 'match' => ['admin.visits.*'], 'can' => 'visits.view'],

                ['key' => 'scheduling', 'label' => 'Scheduling', 'icon' => 'fa-calendar-check', 'items' => [
                    ['label' => 'Appointments', 'icon' => 'fa-calendar-days', 'route' => 'admin.appointments.index', 'match' => ['admin.appointments.index', 'admin.appointments.show'], 'can' => 'appointments.view'],
                    ['label' => 'Check-in queue', 'icon' => 'fa-users-line', 'route' => 'admin.appointments.queue', 'match' => ['admin.appointments.queue'], 'can' => 'appointments.view'],
                    ['label' => 'Doctor availability', 'icon' => 'fa-user-clock', 'route' => 'admin.schedules.index', 'match' => ['admin.schedules.*'], 'can' => 'appointments.view'],
                ]],

                ['key' => 'diagnostics', 'label' => 'Diagnostics', 'icon' => 'fa-microscope', 'items' => [
                    ['label' => 'Lab orders', 'icon' => 'fa-flask', 'route' => 'admin.lab-orders.index', 'match' => ['admin.lab-orders.*'], 'can' => 'lab.view'],
                    ['label' => 'Radiology orders', 'icon' => 'fa-x-ray', 'route' => 'admin.radiology-orders.index', 'match' => ['admin.radiology-orders.*'], 'can' => 'radiology.view'],
                ]],

                ['key' => 'pharmacy', 'label' => 'Pharmacy', 'icon' => 'fa-prescription-bottle-medical', 'gate' => 'pharmacy.view', 'items' => [
                    ['label' => 'Stock items', 'icon' => 'fa-boxes-stacked', 'route' => 'admin.stock.index', 'match' => ['admin.stock.index', 'admin.stock.show']],
                    ['label' => 'Stock alerts', 'icon' => 'fa-triangle-exclamation', 'route' => 'admin.stock.alerts', 'match' => ['admin.stock.alerts']],
                    ['label' => 'Stock ledger', 'icon' => 'fa-book', 'route' => 'admin.stock.movements', 'match' => ['admin.stock.movements']],
                ]],

                ['key' => 'ipd', 'label' => 'Inpatient', 'icon' => 'fa-hospital-user', 'gate' => 'ipd.view', 'items' => [
                    ['label' => 'Occupancy board', 'icon' => 'fa-table-cells-large', 'route' => 'admin.admissions.board', 'match' => ['admin.admissions.board']],
                    ['label' => 'Admissions', 'icon' => 'fa-bed', 'route' => 'admin.admissions.index', 'match' => ['admin.admissions.index', 'admin.admissions.show']],
                ]],

                // The register, last: you open it when someone new arrives or a
                // detail is wrong — not every time you see a patient. The day's work
                // happens in the visit above, which registers a walk-in itself.
                ['label' => 'Patients', 'icon' => 'fa-user-injured', 'route' => 'admin.patients.index', 'match' => ['admin.patients.*'], 'can' => 'patients.view'],
            ]],

            // ── What the care above turns into. ──────────────────────────────────
            ['key' => 'money', 'label' => 'Billing & finance', 'entries' => [
                ['key' => 'billing', 'label' => 'Billing', 'icon' => 'fa-file-invoice-dollar', 'items' => [
                    ['label' => 'Invoices', 'icon' => 'fa-file-invoice-dollar', 'route' => 'admin.invoices.index', 'match' => ['admin.invoices.*'], 'can' => 'billing.view'],
                    ['label' => 'Insurance claims', 'icon' => 'fa-file-medical', 'route' => 'admin.insurance-claims.index', 'match' => ['admin.insurance-claims.*'], 'can' => 'insurance.view'],
                ]],
                // Cards are money work, not a patient detail: a card desk looks
                // things up by card, not by remembering whose it is. The panel on
                // the patient page stays — it is the same rows, filtered to one
                // person (docs/cards.md).
                ['key' => 'cards', 'label' => 'Cards', 'icon' => 'fa-wallet', 'gate' => 'patients.card.manage', 'items' => [
                    ['label' => 'Cards', 'icon' => 'fa-credit-card', 'route' => 'admin.cards.index', 'match' => ['admin.cards.*']],
                    ['label' => 'Card records', 'icon' => 'fa-receipt', 'route' => 'admin.card-records.index', 'match' => ['admin.card-records.*']],
                ]],

                ['key' => 'finance', 'label' => 'Finance', 'icon' => 'fa-chart-line', 'items' => [
                    ['label' => 'Reports', 'icon' => 'fa-chart-line', 'route' => 'admin.reports.index', 'match' => ['admin.reports.*'], 'can' => 'reports.view'],
                    ['label' => 'Financial years', 'icon' => 'fa-calendar-alt', 'route' => 'admin.financial-years.index', 'match' => ['admin.financial-years.*'], 'can' => 'finance.view'],
                ]],
            ]],

            // ── Set up once, edited rarely. Nothing here is daily work. ──────────
            ['key' => 'admin', 'label' => 'Administration', 'entries' => [
                ['key' => 'org', 'label' => 'Hospital', 'icon' => 'fa-sitemap', 'items' => [
                    ['label' => 'Departments', 'icon' => 'fa-sitemap', 'route' => 'admin.departments.index', 'match' => ['admin.departments.*'], 'can' => 'access-admin'],
                    ['label' => 'Rooms', 'icon' => 'fa-door-open', 'route' => 'admin.rooms.index', 'match' => ['admin.rooms.*'], 'can' => 'access-admin'],
                    ['label' => 'Wards', 'icon' => 'fa-hospital', 'route' => 'admin.wards.index', 'match' => ['admin.wards.*'], 'can' => 'ipd.view'],
                    ['label' => 'Beds', 'icon' => 'fa-bed-pulse', 'route' => 'admin.beds.index', 'match' => ['admin.beds.*'], 'can' => 'ipd.view'],
                    // Two different things with two different names, side by side so
                    // the difference is visible: the person's clinical profile, and
                    // the account they sign in with.
                    ['label' => 'Staff profiles', 'icon' => 'fa-user-doctor', 'route' => 'admin.staff.index', 'match' => ['admin.staff.*'], 'can' => 'access-admin'],
                    ['label' => 'User accounts', 'icon' => 'fa-user-shield', 'route' => 'admin.users.index', 'match' => ['admin.users.*'], 'can' => 'manage-users'],
                    // Which machines hold an offline copy of this hospital's
                    // records. Beside the staff list because it is the same
                    // question asked of hardware instead of people.
                    ['label' => 'Offline devices', 'icon' => 'fa-laptop-medical', 'route' => 'admin.offline-devices.index', 'match' => ['admin.offline-devices.*'], 'can' => 'manage-users'],
                    // The same subject from the other end: not "who has a copy" but
                    // "am I ready to walk out of signal". Gated on `access-admin`
                    // rather than `manage-users` on purpose — the people who work
                    // offline are not the people who administer users, and hiding
                    // it from them would leave it useful to nobody.
                    ['label' => 'Offline readiness', 'icon' => 'fa-cloud-arrow-down', 'route' => 'admin.offline.readiness', 'match' => ['admin.offline.*'], 'can' => 'access-admin'],
                ]],

                ['key' => 'catalogues', 'label' => 'Catalogues', 'icon' => 'fa-book-medical', 'items' => [
                    ['label' => 'Price list', 'icon' => 'fa-tags', 'route' => 'admin.services.index', 'match' => ['admin.services.*'], 'can' => 'access-admin'],
                    ['label' => 'Lab tests', 'icon' => 'fa-vial', 'route' => 'admin.lab-tests.index', 'match' => ['admin.lab-tests.*'], 'can' => 'lab.view'],
                    ['label' => 'Radiology studies', 'icon' => 'fa-radiation', 'route' => 'admin.radiology-studies.index', 'match' => ['admin.radiology-studies.*'], 'can' => 'radiology.view'],
                    ['label' => 'Stock categories', 'icon' => 'fa-layer-group', 'route' => 'admin.stock-categories.index', 'match' => ['admin.stock-categories.*'], 'can' => 'pharmacy.view'],
                    ['label' => 'Insurance providers', 'icon' => 'fa-shield-heart', 'route' => 'admin.insurance-providers.index', 'match' => ['admin.insurance-providers.*'], 'can' => 'insurance.view'],
                ]],

                // Gated on manage-settings rather than on the pages' own looser
                // checks: the setup checklist renders read-only for everyone else,
                // and advertising an admin chore to a pharmacist is noise.
                ['key' => 'config', 'label' => 'Settings', 'icon' => 'fa-gear', 'gate' => 'manage-settings', 'items' => [
                    ['label' => 'Set up your hospital', 'icon' => 'fa-list-check', 'route' => 'admin.onboarding', 'match' => ['admin.onboarding']],
                    // What every printed document carries at the top of it.
                    ['label' => 'Hospital letterhead', 'icon' => 'fa-stamp', 'route' => 'admin.settings.hospital', 'match' => ['admin.settings.hospital']],
                    ['label' => 'Billing settings', 'icon' => 'fa-coins', 'route' => 'admin.settings.billing', 'match' => ['admin.settings.billing*']],
                    ['label' => 'Subscription', 'icon' => 'fa-star', 'route' => 'admin.subscription.index', 'match' => ['admin.subscription.*']],
                ]],
            ]],
        ];
    }

    /**
     * The menu this person may use: gated, groups of one flattened, and — while
     * their hospital is still in setup — collapsed to the one section that works.
     *
     * @param  \Closure(array<string>): bool|null  $isActive  whether a `match`
     *         list covers the current page; null outside a web request (the API)
     * @return array{sections: list<array<string,mixed>>, activeGroup: string}
     */
    public static function for(User $user, bool $inSetup, ?\Closure $isActive = null): array
    {
        $isActive ??= fn (array $match) => false;
        $allow = fn ($gate) => self::allows($user, $gate);

        $rendered = [];
        $byRoute = [];          // every visible destination, for setup mode below
        $activeGroup = '';

        $resolve = function (array $it) use ($allow, $isActive, &$byRoute) {
            if (isset($it['can']) && ! $allow($it['can'])) {
                return null;
            }
            $it['active'] = $isActive($it['match']);
            $byRoute[$it['route']] = $it;

            return $it;
        };

        foreach (self::sections() as $s) {
            if (isset($s['gate']) && ! $allow($s['gate'])) {
                continue;
            }

            $entries = [];
            foreach ($s['entries'] as $e) {
                // A plain destination.
                if (! isset($e['items'])) {
                    if ($it = $resolve($e)) {
                        $entries[] = $it + ['type' => 'link'];
                    }

                    continue;
                }

                // A group: drop it whole if its gate fails, then filter its children.
                if (isset($e['gate']) && ! $allow($e['gate'])) {
                    continue;
                }

                $items = [];
                $groupActive = false;
                foreach ($e['items'] as $it) {
                    if (! $it = $resolve($it)) {
                        continue;
                    }
                    if ($it['active']) {
                        $groupActive = true;
                    }
                    $items[] = $it;
                }
                if (! $items) {
                    continue;
                }

                // Nobody should have to open a folder to find one thing: a group
                // that gated down to a single child becomes that child.
                if (count($items) === 1) {
                    $entries[] = $items[0] + ['type' => 'link'];

                    continue;
                }

                if ($groupActive) {
                    $activeGroup = $e['key'];
                }
                $entries[] = ['type' => 'group', 'key' => $e['key'], 'label' => $e['label'], 'icon' => $e['icon'], 'active' => $groupActive, 'items' => $items];
            }

            if (! $entries) {
                continue;
            }
            $rendered[] = ['key' => $s['key'], 'label' => $s['label'], 'entries' => $entries];
        }

        // ── Setup mode ──────────────────────────────────────────────────
        // A menu of pages that all bounce back to the wizard is a menu of dead
        // ends, so while setup is outstanding the menu collapses to the one
        // section that still works: Configuration, holding the wizard and the
        // module pages its steps link to, in the order the wizard asks for
        // them. Every entry is checked against the gate's own allow-list, so
        // the menu can never offer a link that would bounce.
        if ($inSetup) {
            $items = [];
            foreach (self::SETUP_ORDER as $route) {
                if (! isset($byRoute[$route]) || ! Str::is(RequireOnboarding::ALLOWED, $route)) {
                    continue;
                }
                $items[] = $byRoute[$route];
            }

            $rendered = $items === [] ? [] : [[
                'key' => 'setup', 'label' => null, 'entries' => [
                    ['type' => 'group', 'key' => 'setup-config', 'label' => 'Configuration', 'icon' => 'fa-gear', 'active' => true, 'items' => $items],
                ],
            ]];
            $activeGroup = 'setup-config';
        }

        return ['sections' => $rendered, 'activeGroup' => $activeGroup];
    }

    private static function allows(User $user, mixed $gate): bool
    {
        if ($gate === null) {
            return true;
        }
        if ($gate === 'super') {
            return $user->isSuperAdmin();
        }
        if (is_array($gate)) {
            foreach ($gate as $permission) {
                if ($user->can($permission)) {
                    return true;
                }
            }

            return false;
        }

        return $user->can($gate);
    }
}
