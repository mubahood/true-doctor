@extends('layouts.marketing')
@section('title', 'Own it — True-Doctor on your own server')
@section('desc', 'The complete True-Doctor hospital system on your own server: pay once, download straight away, and follow a guided installer. One organisation per installation.')
@section('content')
<section class="hero compact"><div class="wrap">
  <div class="eyebrow">Self-hosted edition</div>
  <h1>Your hospital. Your server.</h1>
  <p class="lead">Everything True-Doctor does online — patients, visits, lab, imaging, pharmacy, wards, billing, the books, offline devices and the mobile app — on hosting you own. Pay once; download straight after payment, with a guided installer.</p>
  <div class="ctas"><a href="{{ route('standalone.portal') }}" class="btn ghost">Already bought it? Open your downloads</a></div>
</div></section>

<section><div class="wrap">
  @if(session('error'))<div class="notice bad" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i><span>{{ session('error') }}</span></div>@endif
  @if($errors->any())<div class="notice bad" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i><span>{{ $errors->first() }}</span></div>@endif

  @if($products->isEmpty() || ! $available)
    <div class="formcard"><h2>Coming soon</h2><p>The self-hosted edition opens for sale as soon as its first verified release is published. <a href="{{ route('contact') }}">Tell us you are interested</a> and we will let you know.</p></div>
  @else
    <div class="prices">
      @foreach($products as $product)
        <div class="price">
          <div class="tag">{{ $product->name }}</div>
          <div class="amt">{{ \App\Support\PlatformCurrency::format($product->price_ugx) }}<small> once</small></div>
          @if($product->description)<div class="who">{{ $product->description }}</div>@endif
          <ul>
            <li><i class="fas fa-check" aria-hidden="true"></i> Every clinical and business module</li>
            <li><i class="fas fa-check" aria-hidden="true"></i> One organisation, on your own server</li>
            <li><i class="fas fa-check" aria-hidden="true"></i> {{ $product->updates_months }} months of new releases{{ $product->renewal_price_ugx ? ', renewable' : '' }}</li>
            <li><i class="fas fa-check" aria-hidden="true"></i> {{ empty($product->limits['max_staff']) ? 'Unlimited staff accounts' : 'Up to '.$product->limits['max_staff'].' staff accounts' }}</li>
            <li><i class="fas fa-check" aria-hidden="true"></i> Download, licence and guide right after payment</li>
          </ul>
          <form method="post" action="{{ route('standalone.checkout', $product) }}" class="own-form">
            @csrf
            <div class="f"><label for="buy-name-{{ $product->id }}">Your organisation's name</label><input id="buy-name-{{ $product->id }}" name="name" required maxlength="190" value="{{ old('name') }}"></div>
            <div class="f"><label for="buy-email-{{ $product->id }}">Email for your downloads</label><input id="buy-email-{{ $product->id }}" type="email" name="email" required value="{{ old('email') }}"></div>
            <div class="f"><label for="buy-domain-{{ $product->id }}">Address you will install on</label><input id="buy-domain-{{ $product->id }}" name="domain" required placeholder="hms.yourhospital.com" value="{{ old('domain') }}"><div class="hint">You can change it later from your downloads page.</div></div>
            <button class="btn" type="submit">Pay securely <i class="fas fa-lock" aria-hidden="true"></i></button>
          </form>
        </div>
      @endforeach
    </div>
  @endif
</div></section>

<section class="band-surface band"><div class="wrap">
  <div class="sec-head"><h2>How it works</h2></div>
  <div class="own-steps">
    <div><b>1 · Pay</b><span>Choose a plan and pay with mobile money or card. Your licence is issued the moment payment is confirmed.</span></div>
    <div><b>2 · Download</b><span>Your private page has the installation file, your licence and the step-by-step guide, together. We email you the link too.</span></div>
    <div><b>3 · Install</b><span>Upload to your cPanel hosting, open the installer, answer a few questions. No programmer needed.</span></div>
    <div><b>4 · Stay current</b><span>New releases install from inside the system, with a backup first and automatic rollback.</span></div>
  </div>
  @if($latest)<p class="own-latest">Latest release: <b>{{ $latest->version }}</b>, {{ $latest->published_at?->format('d M Y') }}.</p>@endif
</div></section>
@endsection
