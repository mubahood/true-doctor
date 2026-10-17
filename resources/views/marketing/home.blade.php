@extends('layouts.marketing')
@section('title', 'True-Doctor: clinic and hospital software that works offline')
@section('desc', 'Patients, visits, lab, pharmacy, wards and billing on one record. Keeps working with no network. Open the live demo or start free for 14 days.')
@section('og', 'home')

@section('content')
@php($wa = \App\Support\SupportContact::current())

{{-- 1. Hero: what it is, the two ways in, a person to ask, and the real thing. --}}
<section class="hero home-hero">
  <div class="wrap">
    {{-- The ads say "Hospital Management System"; the heading a visitor lands
         on has to say it too (LandingTrafficTest), so it leads the H1. --}}
    <h1 data-lift><span class="h1-kicker">Hospital management system</span> Run your clinic from one record, <b>even with no network.</b></h1>
    <p class="lead" data-lift>
      Reception, the doctor, the lab, the pharmacy, the ward and the cashier work on the same patient record.
      When the internet drops, the work goes on and is sent when it returns.
    </p>
    <div class="ctas" data-lift>
      @auth
        <a href="{{ route('admin.dashboard') }}" class="btn lg">Go to your dashboard <x-site.icon name="arrow-right" size="16" /></a>
      @else
        <a href="{{ route('register') }}" class="btn lg">Start free for 14 days <x-site.icon name="arrow-right" size="16" /></a>
        @if($demo)
          <a href="{{ route('demo') }}" class="btn plain lg">Open the live demo</a>
        @else
          <a href="{{ route('walkthrough') }}" class="btn plain lg">Book a walkthrough</a>
        @endif
      @endauth
    </div>
    @if($wa->whatsappDisplay() !== '')
      <p class="hero-wa" data-lift><x-support.link context="site.general" :icon="false">Questions? WhatsApp us on {{ $wa->whatsappDisplay() }}</x-support.link></p>
    @endif

    <figure class="devices">
      <div class="stage">
        <div class="laptop">
          <div class="frame-bar" aria-hidden="true"><i></i><i></i><i></i><span>true-doctor.online/admin</span></div>
          <x-site.shot name="dashboard" eager sizes="(max-width: 820px) 100vw, 900px" />
        </div>
        <div class="handset"><x-site.shot name="phone" eager /></div>
      </div>
      <figcaption class="shot-cap">Real screens from our demo hospital, Kato Medical Centre. Open it yourself, no sign-up.</figcaption>
    </figure>
  </div>
</section>

{{-- 2. A day at Kato Medical Centre: the joins between departments, shown, not listed. --}}
<section class="band-surface">
  <div class="wrap">
    <div class="sec-head">
      <h2>A day at Kato Medical Centre</h2>
      <p>One record carries each patient through the day. This is what that looks like, from the front desk to the last shilling.</p>
    </div>

    <ol class="day">
      @foreach($day as [$time, $who, $title, $body, $shot])
        <li class="day-step" data-reveal>
          <div class="day-text">
            <div class="day-time"><time>{{ $time }}</time> <span>{{ $who }}</span></div>
            <h3>{{ $title }}</h3>
            <p>{{ $body }}</p>
          </div>
          <div class="frame"><x-site.shot :name="$shot" /></div>
        </li>
      @endforeach
    </ol>
  </div>
</section>

{{-- 3. Made for your kind of practice. --}}
<section>
  <div class="wrap">
    <div class="sec-head">
      <h2>Made for your kind of practice</h2>
      <p>The same system, set up for what you do. Turn off what you do not use and the menu shows only your work.</p>
    </div>
    <div class="grid practices">
      @foreach(config('landing.practices') as $slug => $practice)
        <a href="{{ route('for', $slug) }}" class="card" data-reveal>
          <span class="ic" aria-hidden="true"><x-site.icon :name="$practice['icon']" size="20" /></span>
          <h3>{{ ucfirst($practice['name']) }}</h3>
          <p>{{ $practice['card'] }}</p>
          <span class="card-more">See how <x-site.icon name="arrow-right" size="14" /></span>
        </a>
      @endforeach
    </div>
  </div>
</section>

{{-- 4. Works with no network: one honest section, what works and what waits. --}}
<section class="band-surface">
  <div class="wrap">
    <div class="offline">
      <div>
        <div class="eyebrow">No network</div>
        <h2>When the signal drops, the work does not.</h2>
        <p class="sec-body">
          On the Android app, and in the browser in Field Mode, your team keeps working with no signal.
          What they enter is kept on the device and sent the moment the network returns.
        </p>
        <ul class="ticks">
          <li><x-site.icon name="check" size="16" /><span>Nothing waiting is ever deleted, and the screen always says how much is still to send.</span></li>
          <li><x-site.icon name="check" size="16" /><span>A record sent twice on a bad line is saved once.</span></li>
          <li><x-site.icon name="check" size="16" /><span>If two people change the same record, a person decides. Nothing is overwritten quietly.</span></li>
        </ul>
        <p class="offline-cta"><a href="{{ route('get-app') }}" class="btn ghost">Get the Android app <x-site.icon name="arrow-right" size="16" /></a></p>
      </div>
      <div class="split-card">
        <div>
          <h3><x-site.icon name="wifi-off" size="16" /> Works with no signal</h3>
          <ul>
            <li>Registering and updating patients</li>
            <li>Vitals and the doctor's notes</li>
            <li>Lab results</li>
            <li>Nursing notes and doses given on the ward</li>
          </ul>
        </div>
        <div>
          <h3><x-site.icon name="wifi" size="16" /> Waits for the network</h3>
          <ul>
            <li>Taking payments and closing bills</li>
            <li>Dispensing from stock</li>
            <li>Admitting to a bed</li>
          </ul>
          <p>Money and stock are counted in one place, so they are never counted twice.</p>
        </div>
      </div>
    </div>
  </div>
</section>

{{-- 5. Patients hear from you: shown only once the messages really go out. --}}
@if($patientMessages)
  <section>
    <div class="wrap narrow">
      <div class="sec-head">
        <h2>Patients hear from you</h2>
        <p>Text messages in your clinic's name, only to patients who said yes at the desk. You see every one, and what became of it.</p>
      </div>
      <ul class="ticks">
        <li><x-site.icon name="check" size="16" /><span>The booking, with the doctor, the day and the time.</span></li>
        <li><x-site.icon name="check" size="16" /><span>A reminder the day before, never in the night.</span></li>
        <li><x-site.icon name="check" size="16" /><span>The receipt, with a link to open it, when the bill is paid.</span></li>
        <li><x-site.icon name="check" size="16" /><span>The dental check-up, on the day it falls due.</span></li>
      </ul>
    </div>
  </section>
@endif

{{-- 6. The price, in money a clinic thinks in. --}}
@if($plans->isNotEmpty())
  <section @class(['band-surface' => $patientMessages])>
    <div class="wrap">
      <div class="sec-head">
        <h2>Priced for a clinic, not a corporation</h2>
        <p>Every plan starts with 14 days free, no card. Plans are limited by staff and beds, never by patients.</p>
      </div>
      <div class="prices home-prices">
        @foreach($plans as $plan)
          @php($price = \App\Support\PlatformPrice::make($plan->price, $region->currency()))
          <a href="{{ route('pricing', ['plan' => $plan->slug]) }}" @class(['price', 'feat' => $plan->is_featured])>
            <div class="tag">{{ $plan->name }}</div>
            <div class="amt">{{ $price->label() }}<small> a month</small></div>
            <div class="alt">About {{ $price->perDay() }} a day</div>
          </a>
        @endforeach
        @if($oneOff)
          <a href="{{ route('standalone.products') }}" class="price">
            <div class="tag">Your own copy</div>
            <div class="amt">{{ \App\Support\PlatformPrice::make($oneOff->price_ugx, $region->currency())->label() }}<small> once</small></div>
            <div class="alt">On your own server, with {{ $oneOff->updates_months }} months of updates</div>
          </a>
        @endif
      </div>
      <p class="center-cta"><a href="{{ route('pricing') }}" class="btn ghost">See every plan <x-site.icon name="arrow-right" size="16" /></a></p>
    </div>
  </section>
@endif

{{-- 7. The questions clinic owners ask first. --}}
<section @class(['band-surface' => ! $patientMessages])>
  <div class="wrap">
    <div class="sec-head"><h2>Questions clinic owners ask first</h2></div>
    <x-site.faq :items="array_map(fn ($qa) => [e($qa[0]), e($qa[1])], $faq)" />
  </div>
</section>

{{-- The closing band: the home page's only green one. --}}
<section class="band band-go">
  <div class="wrap">
    <h2>Start today. Ask us anything on WhatsApp.</h2>
    <p>Fourteen days free with every module on. We set your clinic up with you, free, on WhatsApp or a call.</p>
    <div class="ctas">
      @auth
        <a href="{{ route('admin.dashboard') }}" class="btn lg">Go to your dashboard</a>
      @else
        <a href="{{ route('register') }}" class="btn lg">Start free for 14 days</a>
      @endauth
      <x-support.link context="site.general" class="btn ghost lg">WhatsApp us</x-support.link>
    </div>
  </div>
</section>

@endsection

@push('structured-data')
  <script type="application/ld+json">
    {!! json_encode(['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => 'True-Doctor', 'url' => route('home')], JSON_UNESCAPED_SLASHES) !!}
  </script>
@endpush
