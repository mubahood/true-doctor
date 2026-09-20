@extends('layouts.auth')
@section('title', 'Demonstration sign-in')

{{-- The demonstration door.

     The same form and the same POST as the hospital's own sign-in — one lock,
     one rate limiter, one deactivated-account check — with the seeded accounts
     listed beside it. It renders only where those accounts exist; elsewhere
     the route is a 404, so a real deployment gives no hint that it is a thing
     that can exist at all. --}}

@section('form')
<div class="af-eyebrow">Demonstration</div>
<h1 class="af-title">Try True-Doctor</h1>
<p class="af-sub">Pick a role on the right, then sign in. Everything you see is sample data in a sample hospital.</p>

<div class="a-note">
  <i class="fas fa-circle-info" aria-hidden="true"></i>
  <div>
    <b>Nothing here is real.</b>
    <span>The patients, visits and bills are generated. Change anything you like — this is what it is for.</span>
  </div>
</div>

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
             value="{{ old('email') }}" placeholder="Pick a role, or type one" required
             autocomplete="username" inputmode="email" autocapitalize="off" spellcheck="false"
             @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
    </div>
    @error('email')<p class="a-err" id="email-error" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i> {{ $message }}</p>@enderror
  </div>

  <div class="a-field">
    <label class="a-label" for="password">Password</label>
    <div class="a-inwrap">
      <i class="fas fa-lock" aria-hidden="true"></i>
      <input class="a-input has-eye @error('password') is-bad @enderror" id="password" type="password" name="password"
             placeholder="Filled in when you pick a role" required autocomplete="current-password"
             @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
      <button type="button" class="a-eye" data-eye="password" aria-label="Show password"><i class="fas fa-eye" aria-hidden="true"></i></button>
    </div>
    @error('password')<p class="a-err" id="password-error" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i> {{ $message }}</p>@enderror
  </div>

  <x-auth.remember />

  <button type="submit" class="a-btn" data-busy="Signing in…">
    <i class="fas fa-right-to-bracket" aria-hidden="true"></i> Sign in to the demonstration
  </button>
</form>

<div class="a-alt">Running a real hospital? <a href="{{ route('admin.login') }}">Staff sign in</a> · <a href="{{ route('register') }}">Create your hospital</a></div>
<div class="a-alt"><a href="{{ route('home') }}" class="a-back"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to home</a></div>
@endsection

@section('aside')
  <div class="a-panel">
    <div class="a-panel-head">
      <i class="fas fa-flask" aria-hidden="true"></i>
      <h3>Choose a role</h3>
      <span class="count">{{ $demoAccounts->count() }}</span>
    </div>
    <div class="a-accts" role="group" aria-label="Demonstration accounts">
      @foreach($demoAccounts as $account)
        <button type="button" class="a-acct" aria-pressed="false" data-demo-email="{{ $account->email }}"
                data-demo-password="{{ $demoPassword }}">
          <span class="a-acct-main">
            <span class="a-acct-role">{{ $account->role }}</span>
            <span class="a-acct-mail">{{ $account->email }}</span>
          </span>
          <span class="a-acct-tag is-{{ $account->kind }}">{{ $account->tag }}</span>
        </button>
      @endforeach
    </div>
    <div class="a-panel-foot">
      Every account uses the password <span class="a-code">{{ $demoPassword }}</span>.
      Hospitals A and B are separate tenants — sign in as one and you cannot see the other's records.
    </div>
  </div>
@endsection
