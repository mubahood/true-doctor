@extends('layouts.marketing')
@section('title', 'Buy your own copy · True-Doctor')
@section('desc', 'Buy True-Doctor for your own server: your details, payment, then your download, licence, receipt and user manual.')
@section('content')
@php($steps = [1 => 'Choose a plan', 2 => 'Your details', 3 => 'Review and pay', 4 => 'Download'])
<section><div class="wrap buy">
  <ol class="buy-steps" aria-label="Steps">
    @foreach($steps as $n => $label)
      <li @class(['on' => $n === $step, 'done' => $n < $step])><span>{{ $n }}</span> {{ $label }}</li>
    @endforeach
  </ol>

  @if(session('status'))<div class="notice ok" role="status"><x-site.icon name="fa-circle-check" /><span>{{ session('status') }}</span></div>@endif
  @if(session('error'))<div class="notice bad" role="alert"><x-site.icon name="fa-circle-exclamation" /><span>{{ session('error') }}</span></div>@endif
  @if($errors->any())<div class="notice bad" role="alert"><x-site.icon name="fa-circle-exclamation" /><span>{{ $errors->first() }}</span></div>@endif

  @if($product)
    <div class="buy-plan"><b>{{ $product->name }}</b> · {{ \App\Support\PlatformCurrency::format($product->price_ugx) }} once · {{ $product->updates_months }} months of updates
      @if($step < 4)<a href="{{ route('standalone.products') }}">Change plan</a>@endif
    </div>
  @endif

  @if($step === 2)
    <form method="post" action="{{ route('standalone.buy.save', $product) }}" class="formcard buy-form">
      @csrf
      <h1>Your details</h1>
      <p>These go on your licence and your receipt. They are saved as you continue, so nothing is lost if you leave.</p>
      <div class="f"><label for="b-name">Your organisation's name</label><input id="b-name" name="name" required maxlength="190" autocomplete="organization" value="{{ old('name', $draft['name'] ?? '') }}"><div class="hint">As it should appear on the licence, for example Kato Medical Centre.</div></div>
      <div class="f"><label for="b-email">Email</label><input id="b-email" type="email" name="email" required maxlength="190" autocomplete="email" value="{{ old('email', $draft['email'] ?? '') }}"><div class="hint">Your download link and receipt come here. Use one you will keep.</div></div>
      <div class="f"><label for="b-phone">Phone or WhatsApp (optional)</label><input id="b-phone" name="phone" maxlength="30" autocomplete="tel" value="{{ old('phone', $draft['phone'] ?? '') }}"><div class="hint">Only so we can help you install.</div></div>
      <div class="f"><label for="b-domain">Address you will install on</label><input id="b-domain" name="domain" required maxlength="253" placeholder="hms.yourhospital.com" value="{{ old('domain', $draft['domain'] ?? '') }}"><div class="hint">The web address of your server, without https://. You can change it later from your downloads page.</div></div>
      <div class="buy-acts"><a class="btn plain" href="{{ route('standalone.products') }}"><x-site.icon name="fa-arrow-left" /> Back</a><button type="submit" class="btn">Continue <x-site.icon name="fa-arrow-right" /></button></div>
    </form>

  @elseif($step === 3)
    <form method="post" action="{{ route('standalone.pay') }}" class="formcard buy-form">
      @csrf
      <h1>Review and pay</h1>
      <dl class="own-kv">
        <dt>Plan</dt><dd>{{ $product->name }}</dd>
        <dt>Licensed to</dt><dd>{{ $draft['name'] }}</dd>
        <dt>Email</dt><dd>{{ $draft['email'] }}</dd>
        @if(! empty($draft['phone']))<dt>Phone</dt><dd>{{ $draft['phone'] }}</dd>@endif
        <dt>Address</dt><dd class="mono">{{ $draft['domain'] }}</dd>
        <dt>Updates</dt><dd>{{ $product->updates_months }} months included{{ $product->renewal_price_ugx ? ', then '.\App\Support\PlatformCurrency::format($product->renewal_price_ugx).' a year if you want them' : '' }}</dd>
        <dt>To pay</dt><dd><b>{{ \App\Support\PlatformCurrency::format($product->price_ugx) }}</b>, once</dd>
      </dl>
      <p><a href="{{ route('standalone.buy', $product) }}">Change these details</a></p>
      <label class="buy-agree"><input type="checkbox" name="agree" value="1" required> I agree to the <a href="{{ route('terms') }}" target="_blank" rel="noopener">terms</a>: one copy for one organisation, on the address above.</label>
      <p class="own-small">You pay on Pesapal's secure page, by mobile money or card. Then you come straight back here to your download. If anything interrupts you, open this page again: your order and its reference are remembered on this device.</p>
      <div class="buy-acts"><a class="btn plain" href="{{ route('standalone.buy', $product) }}"><x-site.icon name="fa-arrow-left" /> Back</a><button type="submit" class="btn">Pay {{ \App\Support\PlatformCurrency::format($product->price_ugx) }} securely <x-site.icon name="fa-lock" /></button></div>
    </form>

  @else
    <div class="formcard buy-form">
      <h1>{{ $order->status === 'failed' ? 'The payment did not start' : 'Waiting for your payment' }}</h1>
      <dl class="own-kv">
        <dt>Reference</dt><dd class="mono">{{ $order->reference }}</dd>
        <dt>Started</dt><dd>{{ $order->created_at?->format('j M Y, H:i') }}</dd>
        <dt>Amount</dt><dd>UGX {{ number_format((float) $order->amount_ugx) }}</dd>
        <dt>Email</dt><dd>{{ $order->customer_email }}</dd>
      </dl>
      @if($order->status === 'failed' || blank($order->gateway_tracking_id))
        <p>No money was taken for this order. Your details are saved; pay again when you are ready.</p>
        <div class="buy-acts"><a class="btn" href="{{ route('standalone.review') }}">Pay again <x-site.icon name="fa-arrow-right" /></a></div>
      @else
        <p>If you have paid, the confirmation usually arrives within a minute; mobile money can take a little longer. This page checks with the payment service. You will also get an email the moment it is confirmed.</p>
        <form method="post" action="{{ route('standalone.status.check') }}" class="buy-acts">@csrf
          <button type="submit" class="btn">Check my payment <x-site.icon name="fa-arrow-right" /></button>
          <a class="btn plain" href="{{ route('standalone.review') }}">Pay again</a>
        </form>
      @endif
      <p class="own-small">Keep the reference. If something looks wrong, <x-support.link context="standalone.install" :vars="['licence' => $order->reference]">WhatsApp us with it</x-support.link>.</p>
    </div>
  @endif
</div></section>
@endsection
