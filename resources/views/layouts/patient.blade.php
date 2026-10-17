{{-- The pages a patient or a stranger opens without signing in: a patient link
     (/p/{token}) and document verification (/v/{code}). One narrow column in
     the hospital's own name and colour, nothing to navigate to. --}}
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>@yield('title') | {{ $brand->name() ?? config('app.name') }}</title>
  @if($brand->markUrl())
    <link rel="icon" href="{{ $brand->markUrl() }}">
  @else
    <link rel="icon" type="image/png" sizes="48x48" href="{{ asset('images/favicon.png') }}">
  @endif
  <meta name="color-scheme" content="light">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="{{ asset('vendor/fa/css/all.min.css') }}">
  @vite(['resources/css/admin.css'])
  @if($brand->adminCss() !== '')<style>{!! $brand->adminCss() !!}</style>@endif
</head>
<body class="pl-body">
  <main class="pl-shell">
    <header class="pl-brand">
      @if($brand->wideUrl())
        <img class="pl-logo" src="{{ $brand->wideUrl() }}" alt="{{ $brand->name() }}">
      @elseif($brand->markUrl())
        <img class="pl-mark" src="{{ $brand->markUrl() }}" alt="">
      @endif
      @if(! $brand->wideUrl())
        <div class="pl-name">{{ $brand->name() ?? config('app.name') }}</div>
      @endif
      @if($brand->tagline())<div class="pl-tagline">{{ $brand->tagline() }}</div>@endif
    </header>

    <div class="tb-card">
      <div class="tb-card-body">
        @yield('content')
      </div>
    </div>

    @php($contact = $brand->contact())
    @if($brand->name() && array_filter([$contact['phone'], $contact['address']]))
      <footer class="pl-foot">
        {{ $brand->name() }}
        @if($contact['address']) · {{ $contact['address'] }}@endif
        @if($contact['phone']) · <a href="tel:{{ preg_replace('/[^0-9+]/', '', $contact['phone']) }}">{{ $contact['phone'] }}</a>@endif
      </footer>
    @endif
  </main>
</body>
</html>
