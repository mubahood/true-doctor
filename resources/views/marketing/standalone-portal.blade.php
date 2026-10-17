@extends('layouts.marketing')
@section('title', 'Your downloads · True-Doctor')
@section('desc', 'Your True-Doctor installation package, licence and setup guide.')
@section('content')
<section class="hero compact"><div class="wrap">
  <div class="eyebrow">Your downloads</div>
  <h1>{{ $licence ? 'Everything you need to install.' : 'Your downloads.' }}</h1>
  <p class="lead">{{ $licence ? 'The installation file, your licence and the guide, together.' : 'Enter the email you bought with, and we will send you a private link.' }}</p>
</div></section>

<section><div class="wrap own-portal">
  @if(session('status'))<div class="notice ok" role="status"><x-site.icon name="fa-circle-check" /><span>{{ session('status') }}</span></div>@endif
  @if(session('error'))<div class="notice bad" role="alert"><x-site.icon name="fa-circle-exclamation" /><span>{{ session('error') }}</span></div>@endif
  @if($errors->any())<div class="notice bad" role="alert"><x-site.icon name="fa-circle-exclamation" /><span>{{ $errors->first() }}</span></div>@endif

  @if(! $licence)
    <div class="formcard">
      <h2>Get a private link</h2>
      <p>The link is emailed to you and works for {{ \App\Notifications\StandaloneDownloadReady::LINK_HOURS }} hours.</p>
      <form method="post" action="{{ route('standalone.portal.link') }}">@csrf
        <div class="f"><label for="portal-email">Email address</label><input id="portal-email" type="email" name="email" required maxlength="190" autocomplete="email"></div>
        <button type="submit" class="btn">Email me a link</button>
      </form>
    </div>
  @else
    <div class="own-grid">
      {{-- ── The package ───────────────────────────────────────── --}}
      <div class="own-card own-main">
        <div class="tag">Step 1 · Download</div>
        @if($release)
          <h2>True-Doctor {{ $release->version }}</h2>
          <p class="own-meta">Released {{ $release->published_at?->format('d M Y') }}@if($release->archive_path && is_file($release->archive_path)) · {{ number_format(filesize($release->archive_path) / 1048576, 1) }} MB @endif</p>
          <div class="ctas own-ctas">
            <a class="btn" href="{{ route('standalone.portal.release', $release) }}"><x-site.icon name="fa-download" /> Download installation file</a>
            <a class="btn plain" href="{{ route('standalone.portal.licence') }}"><x-site.icon name="fa-certificate" /> Download licence.json</a>
          </div>
          <p class="own-small">SHA-256 <code>{{ $release->sha256 }}</code></p>
          @if($release->notes)<details class="own-notes"><summary>What's new in {{ $release->version }}</summary>{!! \Illuminate\Support\Str::markdown($release->notes, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</details>@endif
          @if($earlier->isNotEmpty())
            <details class="own-notes"><summary>Earlier releases</summary>
              <ul>@foreach($earlier as $old)<li><a href="{{ route('standalone.portal.release', $old) }}">{{ $old->version }}</a> · {{ $old->published_at?->format('d M Y') }}</li>@endforeach</ul>
            </details>
          @endif
        @else
          <h2>Your release is being prepared</h2>
          <p>No published release is covered by this licence yet. We will email you when it is ready, or <a href="{{ route('contact') }}">contact us</a>.</p>
        @endif
        @if($licence)
          <p class="own-small"><x-support.link context="standalone.install" :vars="['licence' => $licence->id]">Need help installing? WhatsApp us</x-support.link></p>
        @endif
      </div>

      {{-- ── The licence ───────────────────────────────────────── --}}
      <div class="own-card">
        <div class="tag">Your licence</div>
        <dl class="own-kv">
          <dt>Licensed to</dt><dd>{{ $licence->customer_name }}</dd>
          <dt>Address</dt><dd class="mono">{{ $licence->domain() }}</dd>
          <dt>Updates until</dt><dd>{{ $licence->updates_until->format('d M Y') }}{{ $licence->updates_until->copy()->endOfDay()->isPast() ? ' (ended)' : '' }}</dd>
          <dt>Licence</dt><dd class="mono own-small">{{ $licence->id }}</dd>
        </dl>
        <details class="own-notes">
          <summary>Change address</summary>
          @if($licence->canChangeDomain())
            <form method="post" action="{{ route('standalone.portal.domain') }}">@csrf
              <div class="f"><label for="new-domain">New address</label><input id="new-domain" name="domain" required placeholder="hms.yourhospital.com"><div class="hint">Then download licence.json again. You can change it once every {{ \App\Models\StandaloneLicence::DOMAIN_CHANGE_DAYS }} days.</div></div>
              <button class="btn sm plain" type="submit">Change address</button>
            </form>
          @else
            <p class="own-small">Changed recently. It can change again after {{ $licence->domain_changed_at?->copy()->addDays(\App\Models\StandaloneLicence::DOMAIN_CHANGE_DAYS)->format('d M Y') }}. <a href="{{ route('contact') }}">Ask us</a> if it cannot wait.</p>
          @endif
        </details>
        @if($renewal)
          <form method="post" action="{{ route('standalone.portal.renew') }}" class="own-renew">@csrf
            <p>Add {{ $renewal->updates_months }} more months of new releases for {{ \App\Support\PlatformCurrency::format($renewal->renewal_price_ugx) }}.</p>
            <button class="btn sm" type="submit">Renew updates</button>
          </form>
        @endif
        <p class="own-small">A licence problem can stop updates. It never stops your staff seeing patients.</p>
      </div>
    </div>

    {{-- ── Receipts and the manual: kept here, never only in an email ── --}}
    <div class="own-grid">
      <div class="own-card">
        <div class="tag">Payments and receipts</div>
        @if($payments->isEmpty())
          <p class="own-small">This licence was issued by us directly (reference <span class="mono">{{ $licence->reference ?? $licence->id }}</span>). Ask us if you need a receipt.</p>
        @else
          <dl class="own-kv">
            @foreach($payments as $p)
              <dt>{{ $p->paid_at?->format('d M Y') }}</dt>
              <dd><span class="mono">{{ $p->reference }}</span> · UGX {{ number_format((float) $p->amount_ugx) }} · <a href="{{ route('standalone.portal.receipt', $p) }}" target="_blank" rel="noopener">Receipt (PDF)</a></dd>
            @endforeach
          </dl>
        @endif
        <p class="own-small">Lost this page? Enter your email on the downloads page and a new private link is sent to you.</p>
      </div>
      <div class="own-card">
        <div class="tag">User manual</div>
        <p>How to use every part of True-Doctor, for each person on your team. It is also inside the system under <b>Help, User manual</b>.</p>
        <div class="ctas own-ctas">
          <a class="btn plain" href="{{ route('manual.pdf') }}" target="_blank" rel="noopener"><x-site.icon name="fa-file-pdf" /> User manual (PDF)</a>
          <a class="btn plain" href="{{ route('manual.index') }}">Read it online</a>
        </div>
      </div>
    </div>

    {{-- ── The guide, right here ─────────────────────────────────── --}}
    <div class="own-card own-guide" id="guide">
      <div class="tag">Step 2 · Install</div>
      <div class="own-guide-head"><span>The full guide, also inside the ZIP as INSTALL.md.</span><a class="btn sm plain" href="{{ route('standalone.portal.guide') }}"><x-site.icon name="fa-file-arrow-down" /> INSTALL.md</a></div>
      <div class="own-md">{!! $guide !!}</div>
    </div>
  @endif
</div></section>
@endsection
