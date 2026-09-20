@extends('layouts.auth')
@section('title', 'Sign in')

{{-- The hospital's own sign-in screen.

     Nothing on this page but the form. The seeded demonstration accounts used
     to sit beside it, which meant a real hospital's sign-in page was also a
     list of logins — and anybody who saw it once learned that such a list
     exists. They now live at /test-login, which only exists where they do. --}}

@section('form')
<div class="af-eyebrow">Staff access</div>
<h1 class="af-title">Sign in</h1>
<p class="af-sub">Hospital staff only — enter your work email and password.</p>

@if(session('status'))
  <div class="a-alert ok" role="status"><i class="fas fa-circle-check" aria-hidden="true"></i><span>{{ session('status') }}</span></div>
@endif

{{-- The banner says what went wrong once; the fields below say where. Both,
     because a screen that marks a field red without a sentence leaves somebody
     guessing, and a sentence with no field marked leaves them hunting. --}}
@if($errors->any())
  <div class="a-alert err" role="alert" aria-live="assertive">
    <i class="fas fa-circle-exclamation" aria-hidden="true"></i><span>{{ $errors->first() }}</span>
  </div>
@endif

<form method="POST" action="{{ route('admin.login') }}" novalidate>
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

  <div class="a-field">
    <label class="a-label" for="password">
      Password
      <a href="{{ route('password.request') }}">Forgot password?</a>
    </label>
    <div class="a-inwrap">
      <i class="fas fa-lock" aria-hidden="true"></i>
      <input class="a-input has-eye @error('password') is-bad @enderror" id="password" type="password" name="password"
             placeholder="Your password" required autocomplete="current-password"
             @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
      <button type="button" class="a-eye" data-eye="password" aria-label="Show password"><i class="fas fa-eye" aria-hidden="true"></i></button>
    </div>
    @error('password')<p class="a-err" id="password-error" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i> {{ $message }}</p>@enderror
  </div>

  <x-auth.remember />

  <button type="submit" class="a-btn" data-busy="Signing in…">
    <i class="fas fa-right-to-bracket" aria-hidden="true"></i> Sign in
  </button>
</form>

<div class="a-alt">New here? <a href="{{ route('register') }}">Create your hospital</a> — 14-day free trial.</div>
@if(\App\Http\Controllers\Auth\AuthenticatedSessionController::demoAvailable())
  <div class="a-alt">Just looking? <a href="{{ route('test-login') }}">Open the demonstration</a> — sample hospital, no sign-up.</div>
@endif
<div class="a-alt"><a href="{{ route('home') }}" class="a-back"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to home</a></div>

<div class="a-secure">
  <span><i class="fas fa-shield-halved" aria-hidden="true"></i> Encrypted</span>
  <span class="dot" aria-hidden="true">•</span><span>Every action logged</span>
  <span class="dot" aria-hidden="true">•</span><span>Per-hospital isolation</span>
</div>
@endsection
