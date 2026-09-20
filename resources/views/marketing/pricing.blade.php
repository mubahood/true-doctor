@extends('layouts.marketing')
@section('title', 'Pricing — True-Doctor')
@section('desc', 'One subscription per hospital, not per seat. Every plan starts with a 14-day free trial, and no card is taken to begin.')

@section('content')

<section class="hero compact">
  <div class="wrap">
    <div class="eyebrow">Pricing</div>
    <h1>One subscription per hospital.</h1>
    <p class="lead">
      Priced per facility, not per seat — bring the whole team, including the night
      shift. Every plan starts with a 14-day free trial and no card is taken to begin.
    </p>
  </div>
</section>

<section style="padding-top:44px;">
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
      @foreach($plans as $i => $plan)
        @php($m = $copy[$plan->slug] ?? ['who' => '', 'feats' => []])
        @php($price = \App\Support\PlatformPrice::make($plan->price, $region->currency()))
        <div class="price {{ $plan->is_featured ? 'feat' : '' }}">
          @if($plan->is_featured)<span class="flag">Most chosen</span>@endif
          <div class="tag">{{ $plan->name }}</div>
          <div class="amt">{{ $plan->priceLabel($region->currency()) }}<small>/month</small></div>
          <div class="alt">{{ $price->yearly() }} a year · billed monthly</div>
          <div class="who">{{ $m['who'] ?: $plan->description }}</div>
          <ul>
            <li><i class="fas fa-check" aria-hidden="true"></i> {{ $plan->limit('max_staff') ? $plan->limit('max_staff').' staff logins' : 'Unlimited staff logins' }}</li>
            <li><i class="fas fa-check" aria-hidden="true"></i> {{ $plan->limit('max_patients') ? number_format($plan->limit('max_patients')).' patients' : 'Unlimited patients' }}</li>
            <li><i class="fas fa-check" aria-hidden="true"></i> {{ $plan->limit('max_beds') ? $plan->limit('max_beds').' inpatient beds' : 'Unlimited inpatient beds' }}</li>
            @foreach($m['feats'] as $feat)<li><i class="fas fa-check" aria-hidden="true"></i> {{ $feat }}</li>@endforeach
          </ul>
          <a href="{{ route('register', ['plan' => $plan->id]) }}" class="btn {{ $plan->is_featured ? '' : 'ghost' }}">
            Start 14-day trial
          </a>
        </div>
      @endforeach
    </div>

    {{-- Asked of the region, not of the last plan to come out of the loop:
         a footnote that depends on a leftover loop variable is one that
         changes meaning the day somebody reorders the plans. --}}
    <p style="text-align:center;color:var(--tx3);font-size:12.5px;margin-top:22px;max-width:620px;margin-left:auto;margin-right:auto;">
      @if($region->currency() === 'USD')
        {{ \App\Support\PlatformPrice::conversionNote() }}
      @else
        Billed monthly in Uganda shillings. Cancel from inside your account at any time.
      @endif
    </p>
  </div>
</section>

{{-- ── What is in which plan ──────────────────────────────────────────
     The card list says what each plan adds; this says, in one place, what a
     hospital gets and does not. A pricing page without it makes somebody
     open three tabs and compare bullet lists by eye. --}}
<section class="band-surface">
  <div class="wrap">
    <div class="sec-head"><h2>What each plan includes</h2></div>
    <div class="cmp-scroll">
      <table class="cmp">
        <caption class="sr-only">Modules included in each subscription plan</caption>
        <thead>
          <tr>
            <th scope="col">Module</th>
            @foreach($plans as $plan)<th scope="col" style="text-align:center;">{{ $plan->name }}</th>@endforeach
          </tr>
        </thead>
        <tbody>
          @foreach($matrix as $label => $inPlans)
            <tr>
              <th scope="row">{{ $label }}</th>
              @foreach($plans as $plan)
                <td>
                  @if(in_array($plan->slug, $inPlans, true))
                    <i class="fas fa-check" aria-hidden="true"></i><span class="sr-only">Included</span>
                  @else
                    <i class="fas fa-minus" aria-hidden="true"></i><span class="sr-only">Not included</span>
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

<section>
  <div class="wrap">
    <div class="sec-head"><h2>About the bill</h2></div>
    <x-site.faq :items="[
      ['Is there really no card needed to start?', 'None. You create the hospital, the trial runs for 14 days with every module switched on, and nothing is charged. If you do nothing at the end of it, access simply pauses — you are not billed by default.'],
      ['Are we charged per member of staff?', 'No. The plan sets a ceiling on how many staff logins you can have, and within it you add as many as you like at no extra cost. Adding the night nurse does not change the bill.'],
      ['What happens if we outgrow a plan?', 'Move up whenever you like from inside your account; the new limits apply immediately. Nothing is migrated or rebuilt — it is the same hospital with a different ceiling.'],
      ['What if a payment is late?', 'The subscription keeps working through a grace period rather than cutting off at midnight. After that, access pauses until it is brought current. Your records are untouched throughout and can still be exported.'],
      ['Can we pay by mobile money?', 'Yes. Subscriptions are settled through a payment page that accepts mobile money, card and bank transfer.'],
      ['Why is the price shown in this currency?', 'Because of where you appear to be reading from. Subscriptions are settled in Uganda shillings; a dollar figure is a conversion at a fixed rate, shown so the price means something to a reader anywhere. Use the switch above if it has guessed wrong.'],
    ]" />
  </div>
</section>

<section class="band-surface band">
  <div class="wrap">
    <h2>Not sure which plan fits?</h2>
    <p>Tell us how many staff you have and which departments you run, and we will tell you which plan to start on — including if that is the cheapest one.</p>
    <div class="ctas">
      <a href="{{ route('contact') }}" class="btn lg">Ask us <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
      @if($demo)<a href="{{ route('test-login') }}" class="btn ghost lg">Look around the demo first</a>@endif
    </div>
  </div>
</section>

@endsection
