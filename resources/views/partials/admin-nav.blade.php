@php
  /**
   * The back-office menu.
   *
   * It is ordered the way a day runs, not the way the codebase is filed:
   *
   *   PATIENT CARE  →  BILLING & FINANCE  →  ADMINISTRATION
   *
   * The dashboard is deliberately NOT a row here: the brand mark at the top of
   * the sidebar already goes there, and one destination does not need two
   * controls stacked on top of each other.
   *
   * and inside PATIENT CARE, by how much of the day each thing takes. VISITS
   * leads: everything a patient does hangs off a visit (docs/visits.md), and
   * that page opens one for a booked patient or a walk-in, registering them on
   * the spot. Then the diary, the orders raised inside a visit, dispensing,
   * admissions — and the patient REGISTER last, because you open it when
   * someone new arrives or a detail is wrong, not every time you see them.
   * So "where do I start, and what comes next" is answered by reading down.
   *
   * Two rules keep it from drifting back into a junk drawer:
   *
   *  1. WORK AND SETUP ARE NOT MIXED. "Lab orders" is a queue that changes by
   *     the hour; "Lab tests" is a list edited twice a year. They used to sit
   *     side by side under "Laboratory", looking identical. Every catalogue,
   *     every list, every settings page now lives under ADMINISTRATION, so the
   *     care sections contain only today's work.
   *  2. THE MENU LABEL IS THE PAGE TITLE. Click "Lab tests", land on a page
   *     headed "Lab tests". `SidebarMenuTest` walks every entry and asserts it.
   *
   * @var \App\Models\User $u
   */
  $u = Auth::user();

  // Setup mode: the admin is still being held in the wizard, so every page
  // outside the allow-list below bounces straight back to it. The layout passes
  // this in (it also keys the persisted sidebar on it); the fallback keeps the
  // partial usable on its own.
  $setup = app(\App\Support\OnboardingStatus::class);
  $inSetup = $inSetup ?? $setup->mustCompleteSetup($u);
  $setupProgress = $inSetup ? $setup->progress($u->hospital()->first()) : null;

  // Gate helper: null = always; 'super' = super-admin; string = permission;
  // array = "any of these permissions".
  $allow = function ($gate) use ($u) {
      if ($gate === null) return true;
      if ($gate === 'super') return $u->isSuperAdmin();
      if (is_array($gate)) {
          foreach ($gate as $p) if ($u->can($p)) return true;
          return false;
      }
      return $u->can($gate);
  };

  /**
   * Sections hold entries; an entry is either a destination of its own or a
   * group of them. Every `can` mirrors the destination's OWN authorisation
   * (its Policy::viewAny or its abort_unless), so the menu is an exact map of
   * what this person may open — a wider menu would offer 403s, a narrower one
   * would hide pages they are allowed to use.
   */
  $sections = [
      // ── The SaaS operator's own desk. First, because for them it is the job. ──
      ['key' => 'saas', 'label' => 'Platform', 'gate' => 'super', 'entries' => [
          ['key' => 'platform', 'label' => 'SaaS central', 'icon' => 'fa-shield-halved', 'items' => [
              ['label' => 'Hospitals', 'icon' => 'fa-hospital', 'route' => 'super.hospitals.index', 'match' => ['super.hospitals.*']],
              ['label' => 'Plans', 'icon' => 'fa-layer-group', 'route' => 'super.plans.index', 'match' => ['super.plans.*']],
              ['label' => 'Subscriptions', 'icon' => 'fa-file-invoice-dollar', 'route' => 'super.subscriptions.index', 'match' => ['super.subscriptions.*']],
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

  // ── Resolve visibility, active state, and which group owns this page ──────
  $rendered = [];
  $byRoute = [];          // every visible destination, for setup mode below
  $activeGroup = '';

  $resolve = function (array $it) use ($allow, &$byRoute) {
      if (isset($it['can']) && ! $allow($it['can'])) return null;
      $it['active'] = request()->routeIs(...$it['match']);
      $byRoute[$it['route']] = $it;
      return $it;
  };

  foreach ($sections as $s) {
      if (isset($s['gate']) && ! $allow($s['gate'])) continue;

      $entries = [];
      foreach ($s['entries'] as $e) {
          // A plain destination.
          if (! isset($e['items'])) {
              if ($it = $resolve($e)) $entries[] = $it + ['type' => 'link'];
              continue;
          }

          // A group: drop it whole if its gate fails, then filter its children.
          if (isset($e['gate']) && ! $allow($e['gate'])) continue;

          $items = [];
          $groupActive = false;
          foreach ($e['items'] as $it) {
              if (! $it = $resolve($it)) continue;
              if ($it['active']) $groupActive = true;
              $items[] = $it;
          }
          if (! $items) continue;

          // Nobody should have to open a folder to find one thing: a group
          // that gated down to a single child becomes that child.
          if (count($items) === 1) {
              $entries[] = $items[0] + ['type' => 'link'];
              continue;
          }

          if ($groupActive) $activeGroup = $e['key'];
          $entries[] = ['type' => 'group', 'key' => $e['key'], 'label' => $e['label'], 'icon' => $e['icon'], 'active' => $groupActive, 'items' => $items];
      }

      if (! $entries) continue;
      $rendered[] = ['key' => $s['key'], 'label' => $s['label'], 'entries' => $entries];
  }

  // ── Setup mode ──────────────────────────────────────────────────────────
  // A menu of pages that all bounce back to the wizard is a menu of dead ends,
  // so while setup is outstanding the sidebar collapses to the one section that
  // still works: Configuration, holding the wizard and the module pages its
  // steps link to, in the order the wizard asks for them. Every entry is
  // checked against the gate's own allow-list, so the menu can never offer a
  // link that would bounce.
  if ($inSetup) {
      $setupOrder = [
          'admin.onboarding',                                                     // the wizard itself
          'admin.settings.billing', 'admin.departments.index',                    // required steps
          'admin.services.index', 'admin.users.index', 'admin.offline-devices.index',
          'admin.rooms.index', 'admin.wards.index', 'admin.beds.index',           // recommended steps
          'admin.lab-tests.index', 'admin.radiology-studies.index', 'admin.stock-categories.index',
          'admin.subscription.index',                                             // always reachable
      ];

      $items = [];
      foreach ($setupOrder as $route) {
          if (! isset($byRoute[$route]) || ! \Illuminate\Support\Str::is(\App\Http\Middleware\RequireOnboarding::ALLOWED, $route)) continue;
          $items[] = $byRoute[$route];
      }

      $rendered = $items === [] ? [] : [[
          'key' => 'setup', 'label' => null, 'entries' => [
              ['type' => 'group', 'key' => 'setup-config', 'label' => 'Configuration', 'icon' => 'fa-gear', 'active' => true, 'items' => $items],
          ],
      ]];
      $activeGroup = 'setup-config';
  }
@endphp

@if($inSetup)
  {{-- Why the menu is short, and how far off the end of it is. --}}
  <div class="tb-nav-setup">
    <x-ui.progress :value="$setupProgress['done']" :max="$setupProgress['total']" label="Setup" />
    <p>The rest of the menu unlocks when your hospital is set up.</p>
  </div>
@endif

{{-- Each section is its own labelled list, so the hierarchy a sighted user gets
     from the headings is the same one a screen reader announces. --}}
<div class="tb-nav-list" x-data="tdNav('{{ $activeGroup }}')">
  @foreach($rendered as $s)
    <div class="tb-nav-sec">
      @if($s['label'])
        <h2 class="tb-nav-section" id="navsec-{{ $s['key'] }}">{{ $s['label'] }}</h2>
      @endif
      <ul @if($s['label']) aria-labelledby="navsec-{{ $s['key'] }}" @endif>
        @foreach($s['entries'] as $e)
          @if($e['type'] === 'link')
            {{-- A destination in its own right: same rank as a group header, no caret. --}}
            <li class="tb-nav-item {{ $e['active'] ? 'active' : '' }}" data-nav-item>
              <a wire:navigate.hover href="{{ route($e['route']) }}" @if($e['active']) aria-current="page" @endif>
                <i class="fas {{ $e['icon'] }}" aria-hidden="true"></i><span class="glabel">{{ $e['label'] }}</span>
              </a>
            </li>
          @else
            <li class="tb-nav-group">
              <button type="button" class="tb-nav-gh {{ $e['active'] ? 'has-active' : '' }}" data-nav-group="{{ $e['key'] }}"
                      :class="{ 'open': open === '{{ $e['key'] }}' }"
                      @click="toggle('{{ $e['key'] }}')"
                      :aria-expanded="open === '{{ $e['key'] }}'"
                      aria-controls="navsub-{{ $e['key'] }}">
                <i class="fas {{ $e['icon'] }} gicon" aria-hidden="true"></i>
                <span class="glabel">{{ $e['label'] }}</span>
                <i class="fas fa-chevron-down gcaret" aria-hidden="true"></i>
              </button>
              <ul class="tb-nav-sub" id="navsub-{{ $e['key'] }}" x-show="open === '{{ $e['key'] }}'" x-collapse x-cloak>
                @foreach($e['items'] as $it)
                  <li class="tb-nav-item {{ $it['active'] ? 'active' : '' }}" data-nav-item data-nav-group="{{ $e['key'] }}">
                    <a wire:navigate.hover href="{{ route($it['route']) }}" @if($it['active']) aria-current="page" @endif><i class="fas {{ $it['icon'] }}" aria-hidden="true"></i> {{ $it['label'] }}</a>
                  </li>
                @endforeach
              </ul>
            </li>
          @endif
        @endforeach
      </ul>
    </div>
  @endforeach
</div>
