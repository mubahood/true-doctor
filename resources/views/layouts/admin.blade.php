{{-- True-Doctor — Back-office shell (light · square · Inter)
     PJAX/SPA layout: the sidebar, toast host, confirm dialog, offline banner and
     footer are @persist-ed across wire:navigate; shell JS lives in the Vite
     bundle (head) and runs exactly once. See docs/PJAX_LIVEWIRE_IMPROVEMENT_PLAN.md §4.1 --}}
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  {{-- Where this app is served from (not the origin root — this installation
       lives under a subdirectory). The offline client needs it before it can
       register a worker or reach the API. --}}
  <meta name="td-base" content="{{ rtrim(parse_url(config('app.url'), PHP_URL_PATH) ?? '', '/') }}">
  {{-- Which local database this browser's offline copy lives in. There is one
       per (hospital, user), so the readiness screen MUST read the same two ids
       Field Mode does — reporting on a different database than the one Field
       Mode opens would be worse than reporting nothing. --}}
  @auth
    <meta name="td-hospital" content="{{ app(\App\Support\CurrentHospital::class)->id() }}">
    <meta name="td-user" content="{{ auth()->id() }}">
  @endauth
  {{-- Livewire full-page components pass $title as a variable; classic pages yield a section. --}}
  {{-- The hospital's own logo and colour, when it has set them (Settings →
       Hospital); otherwise True-Doctor's. See App\Support\HospitalBrand. --}}
  @php $hospitalBrand = \App\Support\HospitalBrand::current(); @endphp
  {{-- On a hospital's own server the tab says whose system this is. --}}
  @php $titleSuffix = \App\Support\Edition::isStandalone() && $hospitalBrand->isSet() ? $hospitalBrand->name() : 'True-Doctor'; @endphp
  <title>{{ $title ?? (trim($__env->yieldContent('title')) ?: 'Dashboard') }} · {{ $titleSuffix }}</title>
  {{-- The top bar shows the page title without this suffix (admin.js). --}}
  <meta name="td-title-suffix" content="{{ $titleSuffix }}">
  @if($hospitalBrand->markUrl())
    <link rel="icon" href="{{ $hospitalBrand->markUrl() }}">
    <link rel="apple-touch-icon" href="{{ $hospitalBrand->markUrl() }}">
  @else
    <link rel="icon" type="image/png" sizes="48x48" href="{{ asset('images/favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/logo-192.png') }}">
  @endif
  <meta name="theme-color" content="{{ $hospitalBrand->colour() ?? '#ffffff' }}">
  <meta name="color-scheme" content="light">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@200;300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="{{ asset('vendor/fa/css/all.min.css') }}">
  <style>[x-cloak]{display:none!important}</style>
  @vite(['resources/css/admin.css', 'resources/js/admin.js'])
  @livewireStyles
  {{-- After the stylesheet, so the hospital's colour wins the cascade. Only
       ever `#RRGGBB` values (BrandColour::normalise), never raw input. --}}
  @if($hospitalBrand->adminCss() !== '')<style>{!! $hospitalBrand->adminCss() !!}</style>@endif
  @stack('styles')
</head>
<body @class(['has-brand' => $hospitalBrand->colour() !== null])>
@php
  /** @var \App\Models\User $u */
  $u = Auth::user();
  $orgName = $u->isSuperAdmin() ? 'SaaS Central' : ($u->hospital?->name ?? 'Hospital');

  // While setup is outstanding the sidebar shows only what the gate still
  // allows. It is persisted under its own key so that finishing setup renders
  // the full menu on the very next navigation instead of keeping the stale one.
  $inSetup = app(\App\Support\OnboardingStatus::class)->mustCompleteSetup($u);
@endphp

<div class="tb-wrapper">

  {{-- ═══════ Sidebar (persisted: no flicker, no accordion replay, scroll kept) ═══════ --}}
  @persist($inSetup ? 'sidebar-setup' : 'sidebar')
  <aside class="tb-sidebar" x-data="tdSidebar" :class="{'open': open}" aria-label="Main navigation">
    <div class="tb-sidebar-brand">
      {{-- The one way to the dashboard: it has no menu row of its own, since the
           brand already is that link. During setup the dashboard bounces back to
           the wizard, so the brand points there instead. --}}
      <a wire:navigate.hover href="{{ route($inSetup ? 'admin.onboarding' : 'admin.dashboard') }}" class="tb-flex" style="gap:11px;min-width:0;flex:1;"
         aria-label="{{ $inSetup ? 'Set up your hospital' : 'Dashboard' }}" title="{{ $inSetup ? 'Set up your hospital' : 'Dashboard' }}">
        @if(! $u->isSuperAdmin() && $hospitalBrand->markUrl())
          {{-- A hospital with its own (square) logo sees itself at the top of the menu. --}}
          <img src="{{ $hospitalBrand->markUrl() }}" class="bk" alt="">
          <span class="btext">
            <span class="bt" title="{{ $orgName }}">{{ $orgName }}</span>
            <span class="bs">True-Doctor</span>
          </span>
        @else
          <img src="{{ asset('images/logo-icon.png') }}" class="bk" alt="">
          <span class="btext">
            <span class="bt">True-Doctor</span>
            <span class="bs" title="{{ $orgName }}">{{ $orgName }}</span>
          </span>
        @endif
      </a>
      <button class="tb-sidebar-close" type="button" @click="open=false" aria-label="Close menu"><i class="fas fa-xmark" aria-hidden="true"></i></button>
    </div>

    <nav class="tb-nav" style="padding-top:8px;">
      @include('partials.admin-nav', ['inSetup' => $inSetup])
    </nav>

    <div class="tb-sidebar-footer">
      <form method="POST" action="{{ route('admin.logout') }}" id="logout-form">
        @csrf
        <button type="submit" class="tb-logout-btn"><i class="fas fa-right-from-bracket" aria-hidden="true"></i> Sign out</button>
      </form>
    </div>
  </aside>
  <div class="tb-sidebar-backdrop" x-data="tdSidebar" x-show="open" x-cloak @click="open=false" x-transition.opacity.duration.150ms></div>
  @endpersist

  {{-- ═══════ Main ═══════ --}}
  <div class="tb-main">
    <header class="tb-topbar" x-data="tdTopbar">
      <div class="tb-topbar-left">
        <button class="tb-topbar-toggle" type="button" @click.stop="toggleSidebar()" aria-label="Toggle menu" aria-controls="td-sidebar"><i class="fas fa-bars" aria-hidden="true"></i></button>
        <span class="tb-page-title" x-text="title">{{ $title ?? (trim($__env->yieldContent('title')) ?: 'Dashboard') }}</span>
      </div>
      <div class="tb-topbar-right">
        {{-- Only ever rendered inside the demonstration tenant; see the
             component, which checks the hospital rather than the email. --}}
        <livewire:shell.demo-invite />
        <x-shell.subscription-badge />
        <x-shell.help-menu />
        <livewire:shell.notification-bell />
        <div class="tb-user-menu" :class="{'open': userMenu}" @click.outside="userMenu=false" @keydown.escape="userMenu=false">
          <button type="button" class="tb-user-trigger" @click="userMenu=!userMenu" :aria-expanded="userMenu" aria-haspopup="menu" aria-label="Account menu">
            <span class="tb-user-avatar-sm" aria-hidden="true">{{ $u->initials }}</span>
            <span class="tb-user-name">{{ $u->name }}</span>
            <i class="fas fa-chevron-down tb-user-caret" aria-hidden="true"></i>
          </button>
          <div class="tb-user-dropdown" role="menu">
            <button type="button" role="menuitem" @click="$dispatch('open-profile'); userMenu=false"><i class="fas fa-user-pen" aria-hidden="true"></i> My profile</button>
            <button type="button" role="menuitem" @click="$dispatch('open-password'); userMenu=false"><i class="fas fa-lock" aria-hidden="true"></i> Change password</button>
            @if($u->isSuperAdmin())<a role="menuitem" wire:navigate href="{{ route('admin.settings.index') }}"><i class="fas fa-gear" aria-hidden="true"></i> Site settings</a>@endif
            <hr>
            <button type="submit" form="logout-form" role="menuitem" class="danger"><i class="fas fa-right-from-bracket" aria-hidden="true"></i> Sign out</button>
          </div>
        </div>
      </div>
    </header>

    <main class="tb-content">
      @if(\App\Support\Edition::isStandalone() && $u->can('manage-settings'))
        @if(! \Illuminate\Support\Facades\Cache::has('standalone:scheduler-heartbeat'))
          <div class="tb-alert tb-alert-warning" role="status">
            <i class="fas fa-clock" aria-hidden="true"></i>
            <span>The scheduler has not checked in recently. Add or inspect the cPanel cron job so reminders, queued mail and overnight billing run.</span>
          </div>
        @endif
        @php($installedLicence = app(\App\Support\Licensing\InstalledLicence::class))
        @if(! $installedLicence->isValid())
          <div class="tb-alert tb-alert-warning" role="status">
            <i class="fas fa-certificate" aria-hidden="true"></i>
            <span>The installation licence is missing or invalid. Patient care remains available; contact True-Doctor to restore update access.</span>
          </div>
        @elseif(! $installedLicence->matchesHost(request()->getHost()))
          <div class="tb-alert tb-alert-warning" role="status">
            <i class="fas fa-globe" aria-hidden="true"></i>
            <span>This server address is different from the address on the licence. Patient care remains available; update your licensed address when convenient.</span>
          </div>
        @elseif($installedLicence->updatesUntil()?->endOfDay()->isPast())
          <div class="tb-alert tb-alert-warning" role="status">
            <i class="fas fa-calendar-xmark" aria-hidden="true"></i>
            <span>New-release entitlement has ended. Patient care remains available; renew update access in your buyer portal when convenient.</span>
          </div>
        @endif
      @endif
      @include('partials.flash-bridge')
      {{-- Dual-mode: traditional Blade pages fill @yield('content'); Livewire
           full-page components fill {{ $slot }}. Exactly one is ever populated. --}}
      @yield('content')
      {{ $slot ?? '' }}
    </main>

    @persist('footer')
    <footer class="tb-footer">
      <div class="tb-footer-brand">
        @if(! $u->isSuperAdmin() && $hospitalBrand->markUrl())
          <img src="{{ $hospitalBrand->markUrl() }}" class="tb-footer-mark" alt="">
        @else
          <i class="fas fa-hospital" aria-hidden="true"></i>
        @endif
        <span class="tb-footer-name">{{ $orgName }}</span>
        @if(! $u->isSuperAdmin() && $hospitalBrand->tagline())<span class="tb-footer-tagline">— {{ $hospitalBrand->tagline() }}</span>@endif
      </div>
      <div class="tb-footer-meta">
        <span class="tb-foot-sm-hide"><i class="fas fa-user-shield" aria-hidden="true"></i> {{ $u->name }} · {{ $u->role_label }}</span>
        <span class="sep tb-foot-sm-hide">·</span>
        <span>{{ now()->format('D, d M Y') }}</span>
        <span class="sep">·</span>
        <span x-data="tdClock"><i class="fas fa-clock" aria-hidden="true"></i> <span x-text="t"></span></span>
      </div>
    </footer>
    @endpersist
  </div>
</div>

@persist('toasts')
  @include('partials.toast-host')
@endpersist
@persist('confirm')
  @include('partials.confirm-dialog')
@endpersist
@persist('offline')
  <div x-data="tdOffline" x-show="off" x-cloak class="tb-offline" role="status"><i class="fas fa-wifi" aria-hidden="true"></i> You're offline — changes can't be saved until the connection returns.</div>
@endpersist

<livewire:shell.profile-modal />

@livewireScripts
@stack('scripts')
</body>
</html>
