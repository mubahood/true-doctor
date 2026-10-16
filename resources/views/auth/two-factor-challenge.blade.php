@extends('layouts.auth')
@section('title', 'Two-step sign-in')

@section('form')
<div class="af-eyebrow">Two-step sign-in</div>
<h1 class="af-title">Enter your code</h1>
<p class="af-sub">Open your authenticator app and type the 6-digit code it shows for True-Doctor.</p>

@if($errors->any())
<div class="a-alert err" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i><span>{{ $errors->first() }}</span></div>
@endif

<form method="POST" action="{{ route('two-factor.verify') }}" novalidate>
  @csrf
  <div class="a-field">
    <label class="a-label" for="code">Code</label>
    <div class="a-inwrap">
      <i class="fas fa-shield-halved" aria-hidden="true"></i>
      <input class="a-input @error('code') is-bad @enderror" id="code" type="text" name="code"
             required autofocus autocomplete="one-time-code" inputmode="numeric" maxlength="20"
             placeholder="123 456" @error('code') aria-invalid="true" aria-describedby="code-error" @enderror>
    </div>
    <div class="a-hint">Lost your phone? Type one of your recovery codes instead.</div>
  </div>

  <label class="a-check">
    <input type="checkbox" name="trust" value="1">
    <span>Trust this browser for {{ (int) config('auth.two_factor.trust_days', 30) }} days. Untick on a shared computer.</span>
  </label>

  <button type="submit" class="a-btn" data-busy="Checking…"><i class="fas fa-right-to-bracket" aria-hidden="true"></i> Continue</button>
</form>

<div class="a-alt"><x-support.link context="auth.signin" :vars="['hospital' => \App\Support\HospitalBrand::forSignIn()->name() ?? 'True-Doctor']" class="a-link">No phone and no recovery code? WhatsApp us</x-support.link></div>
<div class="a-alt"><a href="{{ route('admin.login') }}" class="a-back"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to sign in</a></div>
@endsection
