@extends('layouts.auth')
@section('title', 'Reset password')

@section('form')
<div class="af-eyebrow">Account recovery</div>
<h1 class="af-title">Forgot your password?</h1>
<p class="af-sub">No problem. Enter your email and we'll send a secure link to set a new one.</p>

@if(session('status'))
<div class="a-alert ok" role="status"><i class="fas fa-paper-plane"></i><span>{{ session('status') }}</span></div>
@endif
@if($errors->any())
<div class="a-alert err" role="alert"><i class="fas fa-circle-exclamation"></i><span>{{ $errors->first() }}</span></div>
@endif

<form method="POST" action="{{ route('password.email') }}" novalidate>
  @csrf
  <div class="a-field">
    <label class="a-label" for="email">Email address</label>
    <div class="a-inwrap">
      <i class="fas fa-envelope" aria-hidden="true"></i>
      <input class="a-input @error('email') is-bad @enderror" id="email" type="email" name="email"
             value="{{ old('email') }}" placeholder="you@hospital.org" required autofocus
             autocomplete="username" inputmode="email" autocapitalize="off" spellcheck="false"
             @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
    </div>
    @error('email')<p class="a-err" id="email-error" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i> {{ $message }}</p>@enderror
  </div>
  <button type="submit" class="a-btn" data-busy="Sending the link…"><i class="fas fa-paper-plane" aria-hidden="true"></i> Email me a reset link</button>
</form>

<div class="a-alt"><a href="{{ route('admin.login') }}" class="a-back"><i class="fas fa-arrow-left"></i> Back to sign in</a></div>
@endsection
