@extends('layouts.marketing')
@section('title', 'Pricing for clinics and hospitals | True-Doctor')
@section('desc', 'One price per clinic, not per seat, and never per patient. Every plan starts with 14 days free and no card. Pay by mobile money.')
@section('og', 'pricing')

@section('content')

<section class="hero compact">
  <div class="wrap">
    <div class="eyebrow">Pricing</div>
    <h1>One price per clinic. Never per patient.</h1>
    <p class="lead">
      Bring the whole team, night shift included, and see as many patients as come through the door.
      Every plan starts with 14 days free, and no card is taken to begin.
    </p>
    <p class="hero-wa"><x-support.link context="site.page" :vars="['page' => 'pricing']" :icon="false">Not sure which plan fits? WhatsApp us</x-support.link></p>
  </div>
</section>

<section class="pricing-plans">
  <div class="wrap">

    {{-- What currency, and why this one. Somebody being quoted in the wrong
         money should be able to see why and fix it in one click, rather than
         wonder whether the site is broken. --}}
    <div class="cur-row">
      <x-site.currency-switch label="Show prices in" />
      <span class="cur-note">
        @if($region->wasChosen())
          Your choice, remembered on this device.
        @elseif($region->isEastAfrican())
          Shown in shillings because you appear to be in East Africa.
        @else
          Shown in US dollars because you appear to be outside East Africa.
        @endif
      </span>
    </div>

    <div class="prices">
      @foreach($plans as $plan)
        @php($m = $copy[$plan->slug] ?? ['who' => '', 'feats' => []])
        @php($price = \App\Support\PlatformPrice::make($plan->price, $region->currency()))
        @php($picked = request()->query('plan') === $plan->slug)
        <div @class(['price', 'feat' => $plan->is_featured, 'is-picked' => $picked]) @if($picked) id="plan-{{ $plan->slug }}" @endif>
          @if($picked)
            <span class="flag picked">The plan you clicked</span>
          @elseif($plan->is_featured)
            <span class="flag">Most chosen</span>
          @endif
          <div class="tag">{{ $plan->name }}</div>
          <div class="amt">{{ $price->label() }}<small> a month</small></div>
          <div class="per-day">About {{ $price->perDay() }} a day</div>
          <div class="alt">{{ $price->yearly() }} a year, billed monthly</div>
          <div class="who">{{ $m['who'] ?: $plan->description }}</div>
          <ul>
            <li><x-site.icon name="check" size="14" /> {{ $plan->limit('max_staff') ? 'Up to '.$plan->limit('max_staff').' staff accounts' : 'Unlimited staff accounts' }}</li>
            <li><x-site.icon name="check" size="14" /> Unlimited patients</li>
            <li><x-site.icon name="check" size="14" /> {{ $plan->limit('max_beds') ? 'Up to '.$plan->limit('max_beds').' inpatient beds' : 'Unlimited inpatient beds' }}</li>
            @foreach($m['feats'] as $feat)<li><x-site.icon name="check" size="14" /> {{ $feat }}</li>@endforeach
          </ul>
          <a href="{{ route('register', ['plan' => $plan->id]) }}" @class(['btn', 'ghost' => ! $plan->is_featured])>Start free for 14 days</a>
        </div>
      @endforeach
    </div>

    {{-- Asked of the region, not of the last plan to come out of the loop:
         a footnote that depends on a leftover loop variable is one that
         changes meaning the day somebody reorders the plans. --}}
    <p class="price-note">
      @if($region->currency() === 'USD')
        {{ \App\Support\PlatformPrice::conversionNote() }}
      @else
        Billed monthly in Uganda shillings, by mobile money, card or bank. Stop at any time from inside your account.
      @endif
    </p>
  </div>
</section>

{{-- ── The one-off copy, and the help we sell by the job ─────────────── --}}
<section class="band-surface">
  <div class="wrap">
    <div class="sec-head">
      <h2>Rather own it, or want a hand?</h2>
      <p>For a clinic that wants its own server, and for the jobs a busy clinic would rather hand over.</p>
    </div>
    <div class="grid addons">
      @if($oneOff)
        <div class="card">
          <h3>Your own copy</h3>
          <div class="addon-price">{{ \App\Support\PlatformPrice::make($oneOff->price_ugx, $region->currency())->label() }} once</div>
          <p>True-Doctor on your own hosting, with your name on it and your data with you. Includes {{ $oneOff->updates_months }} months of updates{{ $oneOff->renewal_price_ugx ? ', then '.\App\Support\PlatformPrice::make($oneOff->renewal_price_ugx, $region->currency())->label().' a year to keep updating' : '' }}.</p>
          <a href="{{ route('standalone.products') }}" class="card-more">How it works <x-site.icon name="arrow-right" size="14" /></a>
        </div>
      @endif
      <div class="card">
        <h3>Setup and training at your clinic</h3>
        <div class="addon-price">Priced by the visit</div>
        <p>We come to you, set up your price list, wards and staff, and train each role on their own screens. Setup on a call is free for every plan.</p>
        <x-support.link context="site.page" :vars="['page' => 'setup and training']" class="card-more" :icon="false">Ask for a price</x-support.link>
      </div>
      <div class="card">
        <h3>Moving your records in</h3>
        <div class="addon-price">Priced by the job</div>
        <p>Send us your patient list from a spreadsheet, or your price list and stock, and we bring them in for you before your first day.</p>
        <x-support.link context="site.page" :vars="['page' => 'moving our records']" class="card-more" :icon="false">Ask for a price</x-support.link>
      </div>
    </div>
  </div>
</section>

{{-- ── What is in which plan ──────────────────────────────────────────
     The card list says what each plan adds; this says, in one place, what a
     hospital gets and does not. A pricing page without it makes somebody
     open three tabs and compare bullet lists by eye. --}}
<section>
  <div class="wrap">
    <div class="sec-head"><h2>What each plan includes</h2></div>
    <div class="cmp-scroll">
      <table class="cmp">
        <caption class="sr-only">Modules included in each subscription plan</caption>
        <thead>
          <tr>
            <th scope="col">Module</th>
            @foreach($plans as $plan)<th scope="col" class="c">{{ $plan->name }}</th>@endforeach
          </tr>
        </thead>
        <tbody>
          @foreach($matrix as $label => $inPlans)
            <tr>
              <th scope="row">{{ $label }}</th>
              @foreach($plans as $plan)
                <td>
                  @if(in_array($plan->slug, $inPlans, true))
                    <x-site.icon name="check" size="16" class="yes" /><span class="sr-only">Included</span>
                  @else
                    <x-site.icon name="minus" size="16" class="no" /><span class="sr-only">Not included</span>
                  @endif
                </td>
              @endforeach
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="band-surface">
  <div class="wrap">
    <div class="sec-head"><h2>About the bill</h2></div>
    <x-site.faq :items="[
      ['Is there a limit on patients?', 'No. Every plan registers as many patients as you see. Plans differ in staff accounts, beds and modules, never in patients.'],
      ['Is there really no card needed to start?', 'None. You create your clinic, the trial runs for 14 days with every module switched on, and nothing is charged. If you do nothing at the end of it, access pauses. You are not billed by default.'],
      ['Are we charged per member of staff?', 'No. The plan sets a ceiling on staff accounts, and within it you add as many as you like at no extra cost. Adding the night nurse does not change the bill.'],
      ['What happens if we outgrow a plan?', 'Move up whenever you like from inside your account. The new limits apply at once, and it is the same clinic with the same records.'],
      ['What if a payment is late?', 'The subscription keeps working through a grace period rather than cutting off at midnight. After that, access pauses until it is brought up to date. Your records are untouched throughout.'],
      ['Can we pay by mobile money?', 'Yes. Subscriptions are paid through a payment page that takes mobile money, card and bank transfer.'],
      ['Why is the price shown in this currency?', 'Because of where you appear to be reading from. Subscriptions are paid in Uganda shillings; a dollar figure is a conversion at a fixed rate, shown so the price means something anywhere. Use the switch above if it has guessed wrong.'],
    ]" />
  </div>
</section>

<section class="band">
  <div class="wrap">
    <h2>Not sure which plan fits?</h2>
    <p>Tell us how many staff you have and which departments you run, and we will tell you which plan to start on, even if that is the cheapest one.</p>
    <div class="ctas">
      <x-support.link context="site.page" :vars="['page' => 'pricing']" class="btn lg">WhatsApp us</x-support.link>
      @if($demo)<a href="{{ route('demo') }}" class="btn ghost lg">Look around the demo first</a>@endif
    </div>
  </div>
</section>

@endsection

@push('structured-data')
  <x-site.breadcrumbs :trail="['Home' => route('home'), 'Pricing' => null]" />
@endpush
