<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  {{-- A standalone installation's sign-in page is its organisation's: their
       name, logo and colour (HospitalBrand::forSignIn). The online service's is
       shared by every hospital on it and stays True-Doctor's. --}}
  @php
    $signInBrand = \App\Support\HospitalBrand::forSignIn();
    // Their own look only once they have made one — a colour or a logo.
    $org = $signInBrand->isSet();
    // The wide logo if there is one; else a square logo beside the name;
    // else a logo of any other shape, which is a wide logo in all but name.
    $orgWide = $signInBrand->wideUrl();
    $orgMark = $orgWide ?? $signInBrand->markUrl();
    $orgContact = $org ? $signInBrand->contact() : [];
  @endphp
  <title>@yield('title', 'Staff sign in') | {{ $signInBrand->name() ?? 'True-Doctor' }}</title>
  <meta name="description" content="@yield('description', 'Staff sign-in for '.($signInBrand->name() ?? 'True-Doctor hospital management').'.')">
  {{-- Sign-in, sign-up and account pages are doors, not content: never in a search result. --}}
  <meta name="robots" content="noindex, nofollow">
  @if($signInBrand->markUrl())
    <link rel="icon" href="{{ $signInBrand->markUrl() }}">
    <link rel="apple-touch-icon" href="{{ $signInBrand->markUrl() }}">
  @else
    <link rel="icon" type="image/png" sizes="48x48" href="{{ asset('images/favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/logo-192.png') }}">
  @endif
  <meta name="theme-color" content="{{ $signInBrand->colour() ?? '#ffffff' }}">
  <meta name="color-scheme" content="light">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@200;300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="{{ asset('vendor/fa/css/all.min.css') }}">
  @vite(['resources/css/auth.css', 'resources/js/auth.js'])
  @if($signInBrand->authCss() !== '')<style>{!! $signInBrand->authCss() !!}</style>@endif
  @stack('styles')
</head>
<body @class(['is-org' => $org])>
  @unless($org)
    @include('partials.doodle-bg')
  @endunless

  {{-- A hospital that has made the system its own gets a page that is theirs:
       a panel in their colour with their name, their line and how to reach
       them, beside the form. Stacked on a phone, the panel becomes a band. --}}
  @if($org)
    <div class="org-shell">
      <aside class="org-panel" aria-label="{{ $signInBrand->name() }}">
        @include('partials.doodle-bg', ['doodleStroke' => 'rgba(255,255,255,.075)', 'doodleClass' => 'org-doodle'])
        <div class="org-panel-in">
          <span class="org-eyebrow"><i class="fas fa-user-doctor" aria-hidden="true"></i> Staff portal</span>
          <p class="org-name">{{ $signInBrand->name() }}</p>
          @if($signInBrand->tagline())<p class="org-tagline">{{ $signInBrand->tagline() }}</p>@endif
          @if(array_filter([$orgContact['address'] ?? null, $orgContact['phone'] ?? null, $orgContact['website'] ?? null]))
            <ul class="org-contact">
              @if($orgContact['address'])<li><i class="fas fa-location-dot" aria-hidden="true"></i><span>{{ $orgContact['address'] }}</span></li>@endif
              @if($orgContact['phone'])<li><i class="fas fa-phone" aria-hidden="true"></i><span>{{ $orgContact['phone'] }}</span></li>@endif
              @if($orgContact['website'])<li><i class="fas fa-globe" aria-hidden="true"></i><span>{{ preg_replace('#^https?://#', '', $orgContact['website']) }}</span></li>@endif
            </ul>
          @endif
        </div>
        <p class="org-powered"><img src="{{ asset('images/logo-icon.png') }}" alt=""> Runs on True-Doctor</p>
      </aside>
      <div class="org-side">
  @endif

  {{-- One column by default. A page that yields an `aside` gets the context
       rail beside the form; `wide` widens the form column for long forms. --}}
  <div @class(['auth-shell', trim($__env->yieldContent('shell')) => trim($__env->yieldContent('shell')) !== '', 'is-split' => $__env->hasSection('aside')])>
    <main class="auth-main">
      {{-- True-Doctor's mark, unless the hospital's own panel names the page. --}}
      @unless($org)
        <div class="a-brand">
          <img src="{{ asset('images/logo-icon.png') }}" class="mk" alt="">
          <b>True-Doctor</b>
          @if(app()->environment('local'))<span class="env">Local</span>@endif
        </div>
      @endunless

      <div class="card">
        {{-- Inside the card, on white: a logo saved as a JPG with a white
             background still looks right, which it would not on the page. --}}
        @if($orgMark)
          <div class="a-org">
            @if($orgWide)
              <img src="{{ $orgWide }}" class="wm" alt="{{ $signInBrand->name() }}">
            @else
              <img src="{{ $signInBrand->markUrl() }}" class="mk" alt="">
              <b>{{ $signInBrand->name() }}</b>
            @endif
          </div>
        @endif
        @yield('form')
      </div>
    </main>

    @hasSection('aside')
      <aside class="auth-aside">
        @yield('aside')
      </aside>
    @endif
  </div>

  @if($org)
      </div>
    </div>
  @endif

  @stack('scripts')
</body>
</html>
