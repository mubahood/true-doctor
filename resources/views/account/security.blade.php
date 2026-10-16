@extends('layouts.auth')
@section('title', 'Account security')
@section('shell', 'is-wide')

@section('form')
<div class="af-eyebrow">{{ $user->name }}</div>
<h1 class="af-title">Account security</h1>
<p class="af-sub">How you sign in, and where you are signed in.</p>

@if(session('status'))
  <div class="a-alert ok" role="status"><i class="fas fa-circle-check" aria-hidden="true"></i><span>{{ session('status') }}</span></div>
@endif
@if($mustSetUp && ! $pending)
  <div class="a-alert err" role="alert"><i class="fas fa-shield-halved" aria-hidden="true"></i>
    <span>Owners and administrators use two-step sign-in. Set it up to carry on: it takes a minute.</span>
  </div>
@endif

{{-- ── Two-step sign-in ─────────────────────────────────────── --}}
<section class="a-panel sec-block" id="two-factor" aria-labelledby="tf-title">
  <div class="a-panel-head"><i class="fas fa-shield-halved" aria-hidden="true"></i> <b id="tf-title">Two-step sign-in</b>
    @if($enabled)<span class="a-acct-tag sec-on">On</span>@else<span class="a-acct-tag">Off</span>@endif
  </div>
  <div class="a-panel-body">
    @if($recoveryCodes)
      <p class="sec-p"><b>Your recovery codes.</b> Each works once if you lose your phone. Print them or write them down and keep them somewhere safe. They are shown only now.</p>
      <ul class="sec-codes" aria-label="Recovery codes">
        @foreach($recoveryCodes as $code)<li><code class="a-code">{{ $code }}</code></li>@endforeach
      </ul>
      @if($mustSetUp === false && session()->has('recovery_codes'))
        <a href="{{ route('admin.dashboard') }}" class="a-btn sec-btn"><i class="fas fa-check" aria-hidden="true"></i> I have saved them. Continue</a>
      @endif
    @elseif($demo)
      <p class="sec-p">The demo accounts are shared by every visitor, so their sign-in settings stay as they are.</p>
    @elseif($enabled)
      <p class="sec-p">After your password, you type the code from your authenticator app. {{ $codesLeft }} recovery {{ \Illuminate\Support\Str::plural('code', $codesLeft) }} left.</p>
      <form method="POST" action="{{ route('account.security.recovery') }}" class="sec-inline">
        @csrf
        <label class="sr-only" for="pw-recovery">Your password</label>
        <input class="a-input" id="pw-recovery" type="password" name="password" autocomplete="current-password" placeholder="Your password" required>
        <button type="submit" class="a-btn sec-btn"><i class="fas fa-key" aria-hidden="true"></i> Make new recovery codes</button>
      </form>
      <form method="POST" action="{{ route('account.security.disable') }}" class="sec-inline">
        @csrf
        @method('DELETE')
        <label class="sr-only" for="pw-disable">Your password</label>
        <input class="a-input" id="pw-disable" type="password" name="password" autocomplete="current-password" placeholder="Your password" required>
        <button type="submit" class="a-btn sec-btn sec-ghost">
          @if($required)<i class="fas fa-mobile-screen" aria-hidden="true"></i> Move to a new phone @else<i class="fas fa-power-off" aria-hidden="true"></i> Turn off @endif
        </button>
      </form>
    @elseif($pending)
      <ol class="sec-steps">
        <li>Install an authenticator app on your phone: Google Authenticator, Microsoft Authenticator or Authy.</li>
        <li>In the app, add an account and scan this code.</li>
      </ol>
      <div class="sec-qr">
        <div class="sec-qr-img">{!! $qr !!}</div>
        <div>
          <p class="sec-p">Cannot scan? Type this key instead:</p>
          <code class="a-code sec-key">{{ trim(chunk_split($pending['secret'], 4, ' ')) }}</code>
        </div>
      </div>
      <form method="POST" action="{{ route('account.security.confirm') }}" novalidate>
        @csrf
        <div class="a-field">
          <label class="a-label" for="code">3. Type the 6-digit code the app shows</label>
          <div class="a-inwrap">
            <i class="fas fa-hashtag" aria-hidden="true"></i>
            <input class="a-input @error('code') is-bad @enderror" id="code" type="text" name="code" required autofocus
                   autocomplete="one-time-code" inputmode="numeric" maxlength="20" placeholder="123 456"
                   @error('code') aria-invalid="true" aria-describedby="code-error" @enderror>
          </div>
          @error('code')<p class="a-err" id="code-error" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i> {{ $message }}</p>@enderror
        </div>
        <button type="submit" class="a-btn"><i class="fas fa-check" aria-hidden="true"></i> Turn on two-step sign-in</button>
      </form>
    @else
      <p class="sec-p">Add a code from your phone after your password, so a stolen password alone cannot open your account.</p>
      <form method="POST" action="{{ route('account.security.begin') }}">
        @csrf
        <button type="submit" class="a-btn"><i class="fas fa-shield-halved" aria-hidden="true"></i> Set up two-step sign-in</button>
      </form>
    @endif
  </div>
</section>

@unless($mustSetUp)
{{-- ── Trusted browsers ─────────────────────────────────────── --}}
<section class="a-panel sec-block" id="browsers" aria-labelledby="br-title">
  <div class="a-panel-head"><i class="fas fa-desktop" aria-hidden="true"></i> <b id="br-title">Trusted browsers</b></div>
  <div class="a-panel-body">
    <p class="sec-p">{{ $trusted === 0 ? 'No browser skips the code.' : $trusted.' '.\Illuminate\Support\Str::plural('browser', $trusted).' can skip the code for now.' }}</p>
    @if($trusted > 0)
      <form method="POST" action="{{ route('account.security.browsers') }}">
        @csrf
        @method('DELETE')
        <button type="submit" class="a-btn sec-btn sec-ghost"><i class="fas fa-eraser" aria-hidden="true"></i> Ask every browser again</button>
      </form>
    @endif
  </div>
</section>

{{-- ── Apps and devices ─────────────────────────────────────── --}}
<section class="a-panel sec-block" id="devices" aria-labelledby="dv-title">
  <div class="a-panel-head"><i class="fas fa-mobile-screen" aria-hidden="true"></i> <b id="dv-title">Apps signed in</b></div>
  <div class="a-panel-body">
    @forelse($devices as $device)
      <div class="sec-row">
        <div class="sec-row-main">
          <b>{{ $device['name'] === 'api' ? 'An app' : $device['name'] }}</b>
          <span>{{ $device['last_used_at'] ? 'Last used '.\Illuminate\Support\Carbon::parse($device['last_used_at'])->diffForHumans() : 'Not used yet' }}</span>
        </div>
        <form method="POST" action="{{ route('account.security.device', $device['id']) }}">
          @csrf
          @method('DELETE')
          <button type="submit" class="a-link a-linkbtn">Sign out</button>
        </form>
      </div>
    @empty
      <p class="sec-p">No app is signed in to this account.</p>
    @endforelse
  </div>
</section>

{{-- ── Sign out everywhere ──────────────────────────────────── --}}
@unless($demo)
<section class="a-panel sec-block" id="everywhere" aria-labelledby="ev-title">
  <div class="a-panel-head"><i class="fas fa-right-from-bracket" aria-hidden="true"></i> <b id="ev-title">Sign out everywhere else</b></div>
  <div class="a-panel-body">
    <p class="sec-p">Lost a phone, or signed in on a computer you do not trust? This signs out every other browser and app. You stay signed in here.</p>
    <form method="POST" action="{{ route('account.security.everywhere') }}" class="sec-inline">
      @csrf
      <label class="sr-only" for="pw-everywhere">Your password</label>
      <input class="a-input" id="pw-everywhere" type="password" name="password" autocomplete="current-password" placeholder="Your password" required>
      <button type="submit" class="a-btn sec-btn"><i class="fas fa-right-from-bracket" aria-hidden="true"></i> Sign out everywhere else</button>
    </form>
    @error('password')<p class="a-err" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i> {{ $message }}</p>@enderror
  </div>
</section>
@endunless

<div class="a-alt"><a href="{{ route('admin.dashboard') }}" class="a-back"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to the dashboard</a></div>
@endunless

<form method="POST" action="{{ route('admin.logout') }}" class="a-alt">
  @csrf
  <button type="submit" class="a-link a-linkbtn">Sign out</button>
</form>
@endsection
