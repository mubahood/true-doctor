@extends('layouts.marketing')
@section('title', config('landing.pages.own-your-copy.title'))
@section('desc', config('landing.pages.own-your-copy.description'))
@section('og', 'own-your-copy')
@section('content')
<section class="hero compact"><div class="wrap">
  <div class="eyebrow">Self-hosted edition</div>
  <h1>Your hospital. Your server.</h1>
  <p class="lead">Everything True-Doctor does online (patients, visits, lab, imaging, pharmacy, wards, billing, the books, offline devices and the mobile app) on hosting you own. Pay once; download straight after payment, with a guided installer.</p>
  <div class="ctas"><a href="{{ route('standalone.portal') }}" class="btn ghost">Already bought it? Open your downloads</a></div>
</div></section>

<section><div class="wrap">
  @if(session('error'))<div class="notice bad" role="alert"><x-site.icon name="fa-circle-exclamation" /><span>{{ session('error') }}</span></div>@endif
  @if($errors->any())<div class="notice bad" role="alert"><x-site.icon name="fa-circle-exclamation" /><span>{{ $errors->first() }}</span></div>@endif

  @if($owned)
    <div class="notice ok" role="status"><x-site.icon name="fa-circle-check" /><span>You already own a copy on this device. <a href="{{ route('standalone.portal') }}">Open your downloads</a>.</span></div>
  @elseif($resumeOrder)
    <div class="notice" role="status"><x-site.icon name="fa-arrow-right" /><span>You started paying on {{ $resumeOrder->created_at?->format('j M, H:i') }} (reference {{ $resumeOrder->reference }}). <a href="{{ route('standalone.status') }}">See where it stands</a>.</span></div>
  @elseif($resumeDraft && $available)
    <div class="notice" role="status"><x-site.icon name="fa-arrow-right" /><span>Your details are saved. <a href="{{ route('standalone.review') }}">Continue where you stopped</a>.</span></div>
  @endif
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
            <li><x-site.icon name="fa-check" /> Every clinical and business module</li>
            <li><x-site.icon name="fa-check" /> One organisation, on your own server</li>
            <li><x-site.icon name="fa-check" /> {{ $product->updates_months }} months of new releases{{ $product->renewal_price_ugx ? ', renewable' : '' }}</li>
            <li><x-site.icon name="fa-check" /> {{ empty($product->limits['max_staff']) ? 'Unlimited staff accounts' : 'Up to '.$product->limits['max_staff'].' staff accounts' }}</li>
            <li><x-site.icon name="fa-check" /> Download, licence and guide right after payment</li>
          </ul>
          <a class="btn" href="{{ route('standalone.buy', $product) }}">Choose {{ $product->name }}</a>
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

@push('structured-data')
  <x-site.breadcrumbs :trail="['Home' => route('home'), 'Your own copy' => null]" />
@endpush
