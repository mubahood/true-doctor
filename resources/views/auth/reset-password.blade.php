@extends('layouts.auth')
@section('title', 'Set new password')

@section('form')
<div class="af-eyebrow">Account recovery</div>
<h1 class="af-title">Set a new password</h1>
<p class="af-sub">Choose a strong password you'll remember. You'll be signed in straight after.</p>

@if($errors->any())
<div class="a-alert err" role="alert" aria-live="assertive"><i class="fas fa-circle-exclamation" aria-hidden="true"></i>
  <div>{{ $errors->first() }}</div>
</div>
@endif

<form method="POST" action="{{ route('password.store') }}" novalidate>
  @csrf
  <input type="hidden" name="token" value="{{ $request->route('token') }}">

  <div class="a-field">
    <label class="a-label" for="email">Email address</label>
    <div class="a-inwrap">
      <i class="fas fa-envelope"></i>
      <input class="a-input @error('email') is-bad @enderror" id="email" type="email" name="email"
             value="{{ old('email', $request->email) }}" placeholder="you@example.com" required autofocus
             autocomplete="username" inputmode="email" autocapitalize="off" spellcheck="false"
             @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
    </div>
    @error('email')<p class="a-err" id="email-error" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i> {{ $message }}</p>@enderror
  </div>

  <div class="a-field">
    <label class="a-label" for="password">New password</label>
    <div class="a-inwrap">
      <i class="fas fa-lock"></i>
      <input class="a-input has-eye @error('password') is-bad @enderror" id="password" type="password" name="password"
             placeholder="••••••••" required autocomplete="new-password" minlength="6"
             data-strength-for="pw-strength" aria-describedby="password-rule"
             @error('password') aria-invalid="true" @enderror>
      <button type="button" class="a-eye" data-eye="password" aria-label="Show password"><i class="fas fa-eye" aria-hidden="true"></i></button>
    </div>
    @error('password')<p class="a-err" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i> {{ $message }}</p>@enderror
    <div class="a-hint" id="password-rule">At least 6 characters.</div>
    <div class="a-strength" id="pw-strength" hidden>
      <span class="a-strength-bar"><span></span></span>
      <span class="a-strength-word"></span>
    </div>
  </div>

  <div class="a-field">
    <label class="a-label" for="password_confirmation">Confirm new password</label>
    <div class="a-inwrap">
      <i class="fas fa-lock"></i>
      <input class="a-input has-eye" id="password_confirmation" type="password" name="password_confirmation"
             placeholder="••••••••" required autocomplete="new-password" data-match="password" aria-describedby="pw-match">
      <button type="button" class="a-eye" data-eye="password_confirmation" aria-label="Show password"><i class="fas fa-eye" aria-hidden="true"></i></button>
    </div>
    <p class="a-err" id="pw-match" hidden><i class="fas fa-circle-exclamation" aria-hidden="true"></i> The two passwords do not match yet.</p>
  </div>

  <button type="submit" class="a-btn" data-busy="Setting your new password…"><i class="fas fa-key"></i> Reset password</button>
</form>

<div class="a-alt"><a href="{{ route('admin.login') }}" class="a-back"><i class="fas fa-arrow-left"></i> Back to sign in</a></div>
@endsection
