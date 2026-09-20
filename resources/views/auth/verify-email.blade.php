@extends('layouts.auth')
@section('title', 'Verify your email')

@section('form')
<div class="a-icon-badge"><i class="fas fa-envelope-circle-check"></i></div>
<h1 class="af-title">Verify your email</h1>
<p class="af-sub">Thanks for signing up! We've emailed you a verification link. Click it to confirm your
  address. Didn't get it? We'll happily send another.</p>

@if(session('status') == 'verification-link-sent')
<div class="a-alert ok" role="status"><i class="fas fa-circle-check"></i><span>A new verification link has been sent to your email address.</span></div>
@endif

{{-- The send failed. Said plainly, because the alternative was a Symfony stack
     trace — and because the cause is almost always a mail-server setting the
     person reading this cannot fix, so it points them at somebody who can. --}}
@if(session('status') == 'verification-link-failed')
<div class="a-alert err" role="alert">
  <i class="fas fa-triangle-exclamation"></i>
  <span>
    We could not send the email just now. Your account is fine — nothing has been lost.
    Try again in a moment, and tell your administrator if it keeps happening.
  </span>
</div>
@endif

<form method="POST" action="{{ route('verification.send') }}">
  @csrf
  <button type="submit" class="a-btn"><i class="fas fa-paper-plane"></i> Resend verification email</button>
</form>

<form method="POST" action="{{ route('admin.logout') }}" class="a-alt">
  @csrf
  <button type="submit" class="a-back a-linkbtn">
    <i class="fas fa-right-from-bracket"></i> Sign out
  </button>
</form>
@endsection
