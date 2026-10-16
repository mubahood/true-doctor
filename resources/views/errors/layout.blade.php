{{--
  Every error page, in one shell: who this system belongs to, what happened in
  plain words, and the way out.

  Self-contained on purpose — inline styles, no build assets, no session — so a
  page that is reporting a broken server cannot itself break on one. On a
  hospital's own installation it wears their logo, colour and contact line
  (HospitalBrand::forSignIn); online it wears True-Doctor's.
--}}
@php
  try {
      $errorBrand = \App\Support\HospitalBrand::forSignIn();
      $own = $errorBrand->isSet();
  } catch (\Throwable) {
      $errorBrand = new \App\Support\HospitalBrand(null);
      $own = false;
  }
  $palette = ($own ? $errorBrand->palette() : null) ?? ['base' => '#0A6EBD', 'dark' => '#08588F', 'soft' => '#E8F1FB'];
  $orgName = $own ? $errorBrand->name() : null;
  // The same choice the sign-in card makes (HospitalBrand::wideUrl).
  $wideUrl = $own ? $errorBrand->wideUrl() : null;
  $mark = $wideUrl ?? ($own ? $errorBrand->markUrl() : null);
  $wide = $wideUrl !== null;
  $phone = $own ? ($errorBrand->contact()['phone'] ?? null) : null;
  try {
      $support = \App\Support\SupportContact::current();
      $ref = request()->attributes->get('error_ref');
      $helpLink = $support->waLink('admin.error', ['hospital' => $orgName ?? 'True-Doctor', 'ref' => $ref ?? 'none']);
  } catch (\Throwable) {
      $support = null;
      $ref = null;
      $helpLink = '';
  }
  try {
      $home = \Illuminate\Support\Facades\Route::has('admin.dashboard') ? route('admin.dashboard') : url('/');
      $signIn = \Illuminate\Support\Facades\Route::has('admin.login') ? route('admin.login') : url('/');
  } catch (\Throwable) {
      $home = $signIn = '/';
  }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>@yield('title') — {{ $orgName ?? 'True-Doctor' }}</title>
  <meta name="theme-color" content="{{ $palette['base'] }}">
  @if($own && $errorBrand->markUrl())<link rel="icon" href="{{ $errorBrand->markUrl() }}">@else<link rel="icon" type="image/png" href="{{ asset('images/favicon.png') }}">@endif
  @yield('head')
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@200;300;400;500;600&display=swap" rel="stylesheet">
  <style>
    :root{--br:{{ $palette['base'] }};--br-d:{{ $palette['dark'] }};--br-soft:{{ $palette['soft'] }};
      --tx:#111c2e;--mt:#5a6b80;--line:#e2e8f0;--font:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;}
    *{box-sizing:border-box;margin:0;padding:0;}
    body{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:32px 18px;font-family:var(--font);
      color:var(--tx);background:linear-gradient(180deg,var(--br-soft) 0%,#f5f7fa 55%);-webkit-font-smoothing:antialiased;}
    body::before{content:"";position:fixed;inset:0 0 auto 0;height:4px;background:linear-gradient(90deg,var(--br),var(--br-d));}
    .err{width:100%;max-width:520px;background:#fff;border:1px solid var(--line);border-top:3px solid var(--br);
      box-shadow:0 18px 44px -28px rgba(17,28,46,.35);padding:34px 34px 28px;text-align:center;}
    .err-mark{display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:26px;}
    .err-mark img{display:block;max-height:64px;max-width:150px;width:auto;height:auto;object-fit:contain;}
    .err-mark img.wide{max-height:84px;max-width:260px;}
    .err-mark b{font-size:15px;font-weight:600;}
    .err-code{font-size:64px;font-weight:200;line-height:1;letter-spacing:-.03em;color:var(--br);}
    .err-title{margin-top:12px;font-size:21px;font-weight:400;letter-spacing:-.01em;}
    .err-text{margin:10px auto 0;max-width:400px;font-size:14px;line-height:1.6;font-weight:300;color:var(--mt);}
    .err-acts{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-top:26px;}
    .err-btn{display:inline-flex;align-items:center;gap:8px;padding:11px 20px;font:500 13px var(--font);text-decoration:none;
      color:#fff;background:var(--br);border:1px solid var(--br);cursor:pointer;}
    .err-btn:hover{background:var(--br-d);border-color:var(--br-d);}
    .err-btn.ghost{color:var(--br);background:#fff;}
    .err-btn.ghost:hover{background:var(--br-soft);}
    .err-foot{margin-top:26px;padding-top:16px;border-top:1px solid var(--line);font-size:11.5px;color:var(--mt);line-height:1.7;}
    .err-foot b{color:var(--tx);font-weight:500;}
    .err-help{margin-top:18px;font-size:13px;color:var(--mt);line-height:1.6;}
    .err-help a{color:var(--br);font-weight:500;text-decoration:none;}
  </style>
</head>
<body>
  <main class="err" role="main">
    <div class="err-mark">
      @if($mark)
        <img src="{{ $mark }}" alt="{{ $orgName }}" @class(['wide' => $wide])>
        @unless($wide)<b>{{ $orgName }}</b>@endunless
      @elseif($orgName)
        <b>{{ $orgName }}</b>
      @else
        <img src="{{ asset('images/logo-icon.png') }}" alt="" style="max-height:30px"><b>True-Doctor</b>
      @endif
    </div>
    <div class="err-code">@yield('code')</div>
    <h1 class="err-title">@yield('title')</h1>
    <p class="err-text">@yield('message')</p>
    <div class="err-acts">
      @section('actions')
        <a class="err-btn" href="{{ $home }}">Go to the dashboard</a>
        <a class="err-btn ghost" href="javascript:history.back()">Go back</a>
      @show
    </div>
    @if($helpLink !== '' || $support?->phone())
      <p class="err-help">
        @if($ref)Reference <b>{{ $ref }}</b>. @endif
        @if($helpLink !== '')<a href="{{ $helpLink }}" rel="noopener">WhatsApp us {{ $support->whatsappDisplay() }}</a>@endif
        @if($helpLink !== '' && $support->phone()) or @endif
        @if($support?->phone())call <a href="{{ $support->phoneLink() }}">{{ $support->phone() }}</a>@endif
      </p>
    @endif
    <p class="err-foot">
      @if($orgName)
        <b>{{ $orgName }}</b>@if($phone) · {{ $phone }}@endif<br>Runs on True-Doctor
      @else
        <b>True-Doctor</b> hospital management
      @endif
    </p>
  </main>
</body>
</html>
