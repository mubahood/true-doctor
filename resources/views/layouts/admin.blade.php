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
  <title>{{ $title ?? (trim($__env->yieldContent('title')) ?: 'Dashboard') }} · True-Doctor</title>
  <link rel="icon" type="image/png" sizes="48x48" href="{{ asset('images/favicon.png') }}">
  <link rel="apple-touch-icon" href="{{ asset('images/logo-192.png') }}">
  <meta name="theme-color" content="#ffffff">
  <meta name="color-scheme" content="light">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@200;300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="{{ asset('vendor/fa/css/all.min.css') }}">
  <style>[x-cloak]{display:none!important}</style>
  @vite(['resources/css/admin.css', 'resources/js/admin.js'])
  @livewireStyles
  @stack('styles')
</head>
<body>
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
        <img src="{{ asset('images/logo-icon.png') }}" class="bk" alt="">
        <span class="btext">
          <span class="bt">True-Doctor</span>
          <span class="bs" title="{{ $orgName }}">{{ $orgName }}</span>
        </span>
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
        <x-shell.subscription-badge />
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
      @include('partials.flash-bridge')
      {{-- Dual-mode: traditional Blade pages fill @yield('content'); Livewire
           full-page components fill {{ $slot }}. Exactly one is ever populated. --}}
      @yield('content')
      {{ $slot ?? '' }}
    </main>

    @persist('footer')
    <footer class="tb-footer">
      <div class="tb-footer-brand"><i class="fas fa-hospital" aria-hidden="true"></i> {{ $orgName }}</div>
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
