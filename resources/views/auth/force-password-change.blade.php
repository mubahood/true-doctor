@extends('layouts.auth')
@section('title', 'Set your password')

@section('form')
<div class="af-eyebrow">Welcome</div>
<h1 class="af-title">Set your password</h1>
<p class="af-sub">Your account was created with a temporary password. Set your own before continuing.</p>

@if($errors->any())
<div class="a-alert err" role="alert"><i class="fas fa-circle-exclamation"></i>
  <div>{{ $errors->first() }}</div>
</div>
@endif

<form method="POST" action="{{ route('password.update') }}">
  @csrf
  @method('PUT')

  <div class="a-field">
    <label class="a-label" for="current_password">Temporary password</label>
    <div class="a-inwrap">
      <i class="fas fa-lock"></i>
      <input class="a-input" id="current_password" type="password" name="current_password" placeholder="••••••••" required autofocus autocomplete="current-password">
      <button type="button" class="a-eye" data-eye="current_password"><i class="fas fa-eye"></i></button>
    </div>
  </div>

  <div class="a-field">
    <label class="a-label" for="password">New password</label>
    <div class="a-inwrap">
      <i class="fas fa-lock"></i>
      <input class="a-input has-eye" id="password" type="password" name="password" placeholder="••••••••" required autocomplete="new-password">
      <button type="button" class="a-eye" data-eye="password"><i class="fas fa-eye"></i></button>
    </div>
  </div>

  <div class="a-field">
    <label class="a-label" for="password_confirmation">Confirm new password</label>
    <div class="a-inwrap">
      <i class="fas fa-lock"></i>
      <input class="a-input" id="password_confirmation" type="password" name="password_confirmation" placeholder="••••••••" required autocomplete="new-password">
    </div>
  </div>

  <button type="submit" class="a-btn"><i class="fas fa-key"></i> Set password &amp; continue</button>
</form>
@endsection
