@extends('layouts.auth')
@section('title', 'Create your hospital')
@section('shell', 'is-wide')

@section('form')
<div class="af-eyebrow">Get started</div>
<h1 class="af-title">Create your hospital</h1>
<p class="af-sub">Set up your workspace in under a minute — no credit card.</p>

<div class="reg-trial">
  <i class="fas fa-gift" aria-hidden="true"></i>
  <div>
    <b>14 days free</b>
    <span>Full access to every module: patients, appointments, pharmacy, lab, billing and reporting.</span>
  </div>
</div>

{{-- One sentence at the top saying something is wrong, and a mark on each
     field saying which. Neither alone is enough: a red border with no words
     leaves somebody guessing, and words with nothing marked leave them
     hunting through six fields. --}}
@if($errors->any())
  <div class="a-alert err" role="alert" aria-live="assertive">
    <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
    <span>
      {{ $errors->count() === 1 ? $errors->first() : 'Please check the '.$errors->count().' highlighted fields below.' }}
    </span>
  </div>
@endif

<form method="POST" action="{{ route('register') }}" novalidate>
  @csrf

  <div class="a-grid">
    <div class="a-field span2">
      <label class="a-label" for="hospital_name">Hospital / clinic name</label>
      <div class="a-inwrap">
        <i class="fas fa-hospital" aria-hidden="true"></i>
        <input class="a-input @error('hospital_name') is-bad @enderror" id="hospital_name" type="text" name="hospital_name"
               value="{{ old('hospital_name') }}" placeholder="e.g. St. Mary's Clinic" required autofocus
               autocomplete="organization" maxlength="120"
               @error('hospital_name') aria-invalid="true" aria-describedby="hospital_name-error" @enderror>
      </div>
      @error('hospital_name')<p class="a-err" id="hospital_name-error" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i> {{ $message }}</p>@enderror
    </div>

    <div class="a-field">
      <label class="a-label" for="name">Your name</label>
      <div class="a-inwrap">
        <i class="fas fa-user" aria-hidden="true"></i>
        <input class="a-input @error('name') is-bad @enderror" id="name" type="text" name="name"
               value="{{ old('name') }}" placeholder="e.g. Dr. Sarah Nakato" required autocomplete="name" maxlength="120"
               @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
      </div>
      @error('name')<p class="a-err" id="name-error" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i> {{ $message }}</p>@enderror
    </div>

    <div class="a-field">
      <label class="a-label" for="email">Work email</label>
      <div class="a-inwrap">
        <i class="fas fa-envelope" aria-hidden="true"></i>
        <input class="a-input @error('email') is-bad @enderror" id="email" type="email" name="email"
               value="{{ old('email') }}" placeholder="you@hospital.org" required autocomplete="username"
               inputmode="email" autocapitalize="off" spellcheck="false" maxlength="191"
               @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
      </div>
      @error('email')
        <p class="a-err" id="email-error" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i> {{ $message }}</p>
      @else
        <div class="a-hint">This becomes your sign-in — and where your invoices go.</div>
      @enderror
    </div>

    <div class="a-field">
      <label class="a-label" for="password">Password</label>
      <div class="a-inwrap">
        <i class="fas fa-lock" aria-hidden="true"></i>
        <input class="a-input has-eye @error('password') is-bad @enderror" id="password" type="password" name="password"
               required autocomplete="new-password" minlength="8"
               data-strength-for="pw-strength"
               aria-describedby="password-rule {{ $errors->has('password') ? 'password-error' : '' }}"
               @error('password') aria-invalid="true" @enderror>
        <button type="button" class="a-eye" data-eye="password" aria-label="Show password"><i class="fas fa-eye" aria-hidden="true"></i></button>
      </div>
      @error('password')<p class="a-err" id="password-error" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i> {{ $message }}</p>@enderror
      <div class="a-hint" id="password-rule">At least 8 characters, with letters and numbers.</div>
      {{-- Filled in by auth.js. Empty and hidden until typing starts, so the
           page does not open with a meter reading "too short". --}}
      <div class="a-strength" id="pw-strength" hidden>
        <span class="a-strength-bar"><span></span></span>
        <span class="a-strength-word"></span>
      </div>
    </div>

    <div class="a-field">
      <label class="a-label" for="password_confirmation">Confirm password</label>
      <div class="a-inwrap">
        <i class="fas fa-lock" aria-hidden="true"></i>
        <input class="a-input has-eye" id="password_confirmation" type="password" name="password_confirmation"
               required autocomplete="new-password" data-match="password" aria-describedby="pw-match">
        <button type="button" class="a-eye" data-eye="password_confirmation" aria-label="Show password"><i class="fas fa-eye" aria-hidden="true"></i></button>
      </div>
      {{-- Said here rather than after a round trip: retyping two long
           passwords because the server told you they differ is the most
           annoying way to learn it. --}}
      <p class="a-err" id="pw-match" hidden><i class="fas fa-circle-exclamation" aria-hidden="true"></i> The two passwords do not match yet.</p>
    </div>

    <div class="a-field span2">
      <span class="a-label" id="plan-label">
        Choose a plan
        <span class="opt">Free for 14 days, then billed monthly</span>
      </span>
      <div class="reg-plans" role="radiogroup" aria-labelledby="plan-label">
        @foreach($plans as $plan)
          <label class="reg-plan">
            <input type="radio" id="plan-{{ $plan->id }}" name="plan_id" value="{{ $plan->id }}"
                   @checked((int) old('plan_id', $selectedPlan ?? $plans->first()?->id) === $plan->id)>
            <span class="reg-plan-body">
              <i class="fas fa-circle-check reg-plan-tick" aria-hidden="true"></i>
              @if($plan->is_featured)<span class="reg-plan-flag">Most chosen</span>@endif
              <span class="reg-plan-name">{{ $plan->name }}</span>
              <span class="reg-plan-price">{{ $plan->priceLabel() }}<small>/month</small></span>
              <span class="reg-plan-lim">{{ $plan->limit('max_staff') ? $plan->limit('max_staff').' staff accounts' : 'Unlimited staff' }}</span>
              @if($plan->description)<span class="reg-plan-lim">{{ $plan->description }}</span>@endif
            </span>
          </label>
        @endforeach
      </div>
      @error('plan_id')<p class="a-err" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i> {{ $message }}</p>@enderror
      {{-- A converted price has to say so here too, not only on the pricing
           page. Somebody reading a dollar figure on the form they are about
           to submit is entitled to know it is a conversion and what of. --}}
      @if(app(\App\Support\VisitorRegion::class)->currency() === 'USD')
        <div class="a-hint">{{ \App\Support\PlatformPrice::conversionNote() }}</div>
      @else
        <div class="a-hint">Billed monthly in Uganda shillings. Cancel from inside your account at any time.</div>
      @endif
    </div>
  </div>

  {{-- Disabled on submit: creating a hospital twice because somebody
       double-clicked is not a mistake they can undo themselves. --}}
  <button type="submit" class="a-btn" data-busy="Setting up your hospital…">
    <i class="fas fa-rocket" aria-hidden="true"></i> Start 14-day free trial
  </button>

  <p class="a-fineprint">
    By continuing you agree to our <a href="{{ route('terms') }}">Terms</a>
    and <a href="{{ route('privacy') }}">Privacy Policy</a>.
  </p>

  <div class="reg-reassure">
    <span><i class="fas fa-check" aria-hidden="true"></i> No credit card</span>
    <span><i class="fas fa-check" aria-hidden="true"></i> Cancel anytime</span>
    <span><i class="fas fa-check" aria-hidden="true"></i> Every module included</span>
  </div>
</form>

<div class="a-alt">Already have an account? <a href="{{ route('admin.login') }}">Sign in</a></div>
@if(\App\Http\Controllers\Auth\AuthenticatedSessionController::demoAvailable())
  <div class="a-alt">Rather look first? <a href="{{ route('test-login') }}">Open the demonstration</a> — no sign-up needed.</div>
@endif
@endsection

@section('aside')
  <div class="a-panel">
    <div class="a-panel-head">
      <i class="fas fa-list-check" aria-hidden="true"></i>
      <h3>What you get</h3>
    </div>
    <div class="a-panel-body">
      <div class="a-points">
        <div class="a-point"><i class="fas fa-check" aria-hidden="true"></i><span><b>One system, every department</b>Patients, appointments, visits, pharmacy, lab, radiology, inpatient and billing.</span></div>
        <div class="a-point"><i class="fas fa-check" aria-hidden="true"></i><span><b>Your data stays yours</b>Every record is scoped to your hospital and never visible to another.</span></div>
        <div class="a-point"><i class="fas fa-check" aria-hidden="true"></i><span><b>Keeps working offline</b>Field Mode carries on when the network does not, and syncs when it returns.</span></div>
        <div class="a-point"><i class="fas fa-check" aria-hidden="true"></i><span><b>Built for East Africa</b>Your own currency and tax settings, mobile-money friendly billing.</span></div>
        <div class="a-point"><i class="fas fa-check" aria-hidden="true"></i><span><b>Ready in minutes</b>A guided checklist sets up departments, price list and staff.</span></div>
      </div>
    </div>
    <div class="a-panel-foot">
      Questions first? <a href="{{ route('contact') }}" class="a-link">Talk to us</a>.
    </div>
  </div>
@endsection
