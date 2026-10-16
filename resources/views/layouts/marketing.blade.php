@php
  $me = auth()->user();
  // $demo and $region are shared by the view composer in AppServiceProvider,
  // so the pages that extend this layout have them too — a child view's
  // sections are buffered BEFORE the layout runs, so anything defined here
  // is undefined inside them.
  $r = fn ($n) => request()->routeIs($n);
@endphp
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>@yield('title', 'True-Doctor: clinic and hospital management software')</title>
  <meta name="description" content="@yield('desc', 'Run your whole hospital on one system: patients, appointments, visits, pharmacy, lab, billing and reporting. One subscription, one login, per hospital.')">
  <link rel="canonical" href="{{ url()->current() }}">
  <meta name="theme-color" content="#0a6ebd">
  <link rel="icon" type="image/png" sizes="48x48" href="{{ asset('images/favicon.png') }}">
  <link rel="apple-touch-icon" href="{{ asset('images/logo-192.png') }}">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="True-Doctor">
  <meta property="og:title" content="@yield('title', 'True-Doctor: clinic and hospital management software')">
  <meta property="og:description" content="@yield('desc', 'One system for patients, appointments, visits, pharmacy, lab, billing and reporting.')">
  <meta property="og:url" content="{{ url()->current() }}">
  @php
    // A page's own picture (public/images/og/<name>.png, scripts/site-og.js), else the site's.
    $ogName = trim($__env->yieldContent('og'));
    $ogImage = $ogName !== '' && is_file(public_path("images/og/{$ogName}.png")) ? asset("images/og/{$ogName}.png") : asset('images/og.png');
  @endphp
  <meta property="og:image" content="{{ $ogImage }}">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="@yield('title', 'True-Doctor: clinic and hospital management software')">
  <meta name="twitter:description" content="@yield('desc', 'One system for patients, appointments, visits, pharmacy, lab, billing and reporting.')">
  <meta name="twitter:image" content="{{ $ogImage }}">
  @hasSection('noindex')<meta name="robots" content="noindex, nofollow">@endif
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@200;300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="{{ asset('vendor/fa/css/all.min.css') }}">
  @vite(['resources/css/marketing.css', 'resources/js/marketing.js'])

  {{-- What this is, for a search engine. One block, in the layout, so every
       page carries it and no page carries a second contradictory copy. --}}
  <script type="application/ld+json">
    {!! json_encode([
      '@context' => 'https://schema.org',
      '@type' => 'SoftwareApplication',
      'name' => 'True-Doctor',
      'applicationCategory' => 'HealthApplication',
      'operatingSystem' => 'Web',
      'description' => 'Hospital management software: patients, appointments, visits, pharmacy, lab, radiology, inpatient care, billing and reporting on one subscription per hospital.',
      'url' => route('home'),
      'offers' => [
        '@type' => 'AggregateOffer',
        'priceCurrency' => $region->currency(),
        'availability' => 'https://schema.org/InStock',
      ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
  </script>
  @php $ldSupport = \App\Support\SupportContact::current(); @endphp
  @if($ldSupport->phone() !== '')
    <script type="application/ld+json">
      {!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => 'True-Doctor',
        'legalName' => config('company.legal_name') ?: null,
        'url' => route('home'),
        'contactPoint' => array_filter([
          '@type' => 'ContactPoint',
          'telephone' => $ldSupport->phone(),
          'email' => $ldSupport->email() ?: null,
          'contactType' => 'customer support',
          'availableLanguage' => ['en', 'lg', 'sw'],
        ]),
      ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
    </script>
  @endif
  @stack('structured-data')
  @stack('styles')

  {{-- Tells the stylesheet it may hide an element in order to reveal it.
       Inline and in the head, so the starting state is in force before the
       first paint — set from marketing.js instead and you get a flash of the
       finished page collapsing into the unfinished one.

       The timeout is the important half. If marketing.js never arrives — a
       bad deploy, a 404, a blocked script — the class is removed and every
       section is simply visible. Content must never depend on an animation
       that did not run. --}}
  <script>
    (function () {
      var h = document.documentElement;
      h.classList.add('js');
      window.setTimeout(function () {
        if (!h.hasAttribute('data-site-ready')) { h.classList.remove('js'); }
      }, 2500);
    })();
  </script>
</head>
<body>

<a class="skip" href="#main">Skip to content</a>

<header class="site">
  <div class="wrap bar">
    <a href="{{ route('home') }}" class="brand" @if($r('home')) aria-current="page" @endif>
      <img src="{{ asset('images/logo-icon.png') }}" alt=""> True-Doctor
    </a>
    <nav class="nav" aria-label="Main">
      @foreach(['features' => 'Product', 'pricing' => 'Pricing', 'standalone.products' => 'Self-hosted', 'security' => 'Security', 'contact' => 'Contact'] as $route => $label)
        <a href="{{ route($route) }}" class="{{ $r($route) ? 'on' : '' }}"
           @if($r($route)) aria-current="page" @endif>{{ $label }}</a>
      @endforeach
    </nav>
    <div class="hd-r">
      @auth
        <span class="hd-who desk">Signed in as <b>{{ $me->name }}</b></span>
        <a href="{{ route('admin.dashboard') }}" class="btn desk sm">
          <i class="fas fa-gauge-high" aria-hidden="true"></i> Go to dashboard
        </a>
      @else
        @if($demo)<a href="{{ route('demo') }}" class="btn ghost desk sm">Try the demo</a>@endif
        <x-support.link context="site.general" class="btn plain desk sm wa">WhatsApp us</x-support.link>
        <a href="{{ route('admin.login') }}" class="btn ghost desk sm">Sign in</a>
        <a href="{{ route('register') }}" class="btn desk sm">Sign up</a>
      @endauth
      <button class="burger" id="burger" aria-label="Open menu" aria-expanded="false" aria-controls="mmenu">
        <i class="fas fa-bars" aria-hidden="true"></i>
      </button>
    </div>
  </div>
</header>

<div class="mmenu" id="mmenu">
  <x-support.link context="site.general" class="btn wa-menu">WhatsApp us</x-support.link>
  <a href="{{ route('features') }}">Product</a>
  <a href="{{ route('pricing') }}">Pricing</a>
  <a href="{{ route('security') }}">Security</a>
  <a href="{{ route('contact') }}">Contact</a>
  @auth
    <a href="{{ route('admin.dashboard') }}" class="btn"><i class="fas fa-gauge-high" aria-hidden="true"></i> Go to dashboard</a>
    <form method="POST" action="{{ route('admin.logout') }}" class="mmenu-out">
      @csrf
      <button type="submit" class="btn ghost">Sign out</button>
    </form>
  @else
    @if($demo)<a href="{{ route('demo') }}" class="btn ghost">Try the demo</a>@endif
    <a href="{{ route('admin.login') }}" class="btn ghost">Sign in</a>
    <a href="{{ route('register') }}" class="btn">Sign up</a>
  @endauth
</div>

<main id="main">
  {{-- A word from the last request — signing out, mostly. Above the content
       rather than inside a page, because any public page can be the one
       somebody lands on afterwards. --}}
  @if(session('status'))
    <div class="wrap" style="padding-top:22px;">
      <div class="notice ok" role="status">
        <i class="fas fa-circle-check" aria-hidden="true"></i>
        <div style="flex:1;">{{ session('status') }}</div>
        <button type="button" data-dismiss aria-label="Dismiss"
                style="border:none;background:none;cursor:pointer;color:inherit;opacity:.7;">
          <i class="fas fa-xmark" aria-hidden="true"></i>
        </button>
      </div>
    </div>
  @endif

  @yield('content')
</main>

<footer>
  <div class="wrap">
    <div class="foot">
      <div>
        <a href="{{ route('home') }}" class="brand"><img src="{{ asset('images/logo-icon.png') }}" alt="" style="width:26px;height:26px;"> True-Doctor</a>
        <p class="blurb">One system to run your clinic or hospital: patients, clinical care, pharmacy, lab and billing, on a single subscription.</p>
      </div>
      <div>
        <h4>Product</h4>
        <a href="{{ route('features') }}">Modules</a>
        <a href="{{ route('pricing') }}">Pricing</a>
        <a href="{{ route('security') }}">Security</a>
        @if($demo)<a href="{{ route('demo') }}">Try the demo</a>@endif
      </div>
      <div>
        <h4>Built for</h4>
        @foreach(config('landing.practices') as $slug => $practice)
          <a href="{{ route('for', $slug) }}">{{ ucfirst($practice['name']) }}</a>
        @endforeach
        <a href="{{ route('get-app') }}">The Android app</a>
      </div>
      <div>
        <h4>Company</h4>
        <a href="{{ route('about') }}">About us</a>
        <a href="{{ route('walkthrough') }}">Book a walkthrough</a>
        <a href="{{ route('contact') }}">Contact</a>
        <a href="{{ route('privacy') }}">Privacy</a>
        <a href="{{ route('terms') }}">Terms</a>
      </div>
      <div>
        <h4>Get started</h4>
        @auth
          <a href="{{ route('admin.dashboard') }}">Your dashboard</a>
        @else
          <a href="{{ route('register') }}">Start a free trial</a>
          <a href="{{ route('admin.login') }}">Staff sign in</a>
        @endauth
      </div>
      <div class="foot-support">
        <h4>Talk to us</h4>
        <x-support.facts context="site.general" />
      </div>
    </div>

    <div class="foot-bar">
      @php
        // The company facts, each only when set (config/company.php).
        $facts = array_filter([
            ! empty($company['legal_name']) ? 'a product of '.$company['legal_name'] : null,
            ! empty($company['registration_no']) ? 'Reg. No. '.$company['registration_no'] : null,
            implode(', ', array_filter([$company['address'] ?? null, $company['po_box'] ?? null, ! empty($company['address']) ? ($company['country'] ?? null) : null])) ?: null,
        ]);
      @endphp
      <span>&copy; {{ date('Y') }} True-Doctor{{ $facts === [] ? '' : ', '.implode(' · ', $facts) }}.</span>
      <x-site.currency-switch />
    </div>
  </div>
</footer>

<x-support.float context="site.general" />

@stack('scripts')
</body>
</html>
