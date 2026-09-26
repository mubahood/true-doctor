@extends('layouts.auth')
@section('title', 'Set your password')

@section('form')
<div class="af-eyebrow">Welcome</div>
<h1 class="af-title">Set your password</h1>
<p class="af-sub">
  You signed in with a password somebody else chose for you. Pick your own
  to continue — you will use it from now on.
</p>

{{-- Every error is shown beside its field. This page used to read the
     default error bag while the controller wrote to a named one, so a
     rejected password reloaded the form with no message at all. --}}
@if($errors->any())
<div class="a-alert err" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i>
  <div>Your password was not changed — see below.</div>
</div>
@endif

<form method="POST" action="{{ route('password.update') }}" novalidate>
  @csrf
  @method('PUT')

  <div class="a-field">
    <label class="a-label" for="current_password">Password you signed in with</label>
    <div class="a-inwrap">
      <i class="fas fa-lock" aria-hidden="true"></i>
      <input class="a-input has-eye @error('current_password') is-bad @enderror" id="current_password" type="password"
             name="current_password" required autocomplete="current-password"
             @unless($errors->has('password')) autofocus @endunless
             @error('current_password') aria-invalid="true" aria-describedby="current-error" @enderror>
      <button type="button" class="a-eye" data-eye="current_password" aria-label="Show password"><i class="fas fa-eye" aria-hidden="true"></i></button>
    </div>
    @error('current_password')<p class="a-err" id="current-error" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i> {{ $message }}</p>@enderror
  </div>

  <div class="a-field">
    <label class="a-label" for="password">New password</label>
    <div class="a-inwrap">
      <i class="fas fa-lock" aria-hidden="true"></i>
      <input class="a-input has-eye @error('password') is-bad @enderror" id="password" type="password"
             name="password" required autocomplete="new-password" minlength="6"
             data-strength-for="pw-strength"
             @error('password') autofocus aria-invalid="true" @enderror
             aria-describedby="password-rule {{ $errors->has('password') ? 'password-error' : '' }}">
      <button type="button" class="a-eye" data-eye="password" aria-label="Show password"><i class="fas fa-eye" aria-hidden="true"></i></button>
    </div>
    @error('password')<p class="a-err" id="password-error" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i> {{ $message }}</p>@enderror
    <div class="a-hint" id="password-rule">At least 6 characters. Longer is safer.</div>
    <div class="a-strength" id="pw-strength" hidden>
      <span class="a-strength-bar"><span></span></span>
      <span class="a-strength-word"></span>
    </div>
  </div>

  <div class="a-field">
    <label class="a-label" for="password_confirmation">Type the new password again</label>
    <div class="a-inwrap">
      <i class="fas fa-lock" aria-hidden="true"></i>
      <input class="a-input has-eye" id="password_confirmation" type="password" name="password_confirmation"
             required autocomplete="new-password" data-match="password" aria-describedby="pw-match">
      <button type="button" class="a-eye" data-eye="password_confirmation" aria-label="Show password"><i class="fas fa-eye" aria-hidden="true"></i></button>
    </div>
    {{-- Said while typing rather than after a round trip. --}}
    <p class="a-err" id="pw-match" hidden><i class="fas fa-circle-exclamation" aria-hidden="true"></i> The two passwords do not match yet.</p>
  </div>

  {{-- auth.js disables it on submit, so a double-click cannot post twice. --}}
  <button type="submit" class="a-btn"><i class="fas fa-key" aria-hidden="true"></i> Set password &amp; continue</button>
</form>

{{-- Not the right account, or not now: a way out that is not the back
     button, which would only bring them straight back here. --}}
<form method="POST" action="{{ route('admin.logout') }}" class="a-alt">
  @csrf
  Not you? <button type="submit" class="a-link a-linkbtn">Sign out</button>
</form>
@endsection
