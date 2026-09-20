@extends('layouts.marketing')
@section('title', 'Contact — True-Doctor')
@section('desc', 'Ask about a plan, request a walkthrough, or tell us about your hospital. We reply within one business day.')

@section('content')

<section class="hero compact">
  <div class="wrap">
    <div class="eyebrow">Contact</div>
    <h1>Let&rsquo;s get your hospital set up.</h1>
    <p class="lead">
      Tell us how many staff you have and which departments you run. We will tell
      you which plan to start on — including if that is the cheapest one — and set
      you up if you want a hand.
    </p>
  </div>
</section>

<section style="padding-top:44px;">
  <div class="wrap">
    <div class="formcard">

      @if(session('sent'))
        <div class="notice ok" role="status">
          <i class="fas fa-circle-check" aria-hidden="true"></i>
          <div>{{ session('sent') }}</div>
        </div>
      @endif

      @if($errors->any())
        <div class="notice bad" role="alert" aria-live="assertive">
          <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
          <div>{{ $errors->count() === 1 ? $errors->first() : 'Please check the highlighted fields below.' }}</div>
        </div>
      @endif

      <form method="POST" action="{{ route('contact.send') }}" novalidate>
        @csrf

        {{-- The honeypot. Off-screen rather than display:none, because the
             point is that an automated filler DOES fill it; a person never
             sees it, so a person never does. --}}
        <div class="hp" aria-hidden="true">
          <label for="website">Website</label>
          <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
        </div>

        <div class="fgrid">
          <div class="f">
            <label for="name">Your name</label>
            <input type="text" id="name" name="name" value="{{ old('name') }}" required
                   autocomplete="name" maxlength="120" class="{{ $errors->has('name') ? 'bad' : '' }}"
                   @error('name') aria-invalid="true" aria-describedby="name-e" @enderror>
            @error('name')<p class="err" id="name-e" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i> {{ $message }}</p>@enderror
          </div>

          <div class="f">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required
                   autocomplete="email" inputmode="email" autocapitalize="off" spellcheck="false" maxlength="191"
                   class="{{ $errors->has('email') ? 'bad' : '' }}"
                   @error('email') aria-invalid="true" aria-describedby="email-e" @enderror>
            @error('email')<p class="err" id="email-e" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i> {{ $message }}</p>@enderror
          </div>

          <div class="f">
            <label for="hospital">Hospital or clinic <span class="opt">optional</span></label>
            <input type="text" id="hospital" name="hospital" value="{{ old('hospital') }}"
                   autocomplete="organization" maxlength="160">
          </div>

          <div class="f">
            <label for="phone">Phone <span class="opt">optional</span></label>
            <input type="tel" id="phone" name="phone" value="{{ old('phone') }}"
                   autocomplete="tel" inputmode="tel" maxlength="40">
          </div>

          <div class="f span2">
            <label for="size">Roughly how big are you? <span class="opt">optional</span></label>
            <select id="size" name="size">
              <option value="">Prefer not to say</option>
              @foreach([
                'A single practice (1–5 staff)',
                'A small clinic (6–20 staff)',
                'A busy clinic or polyclinic (21–60 staff)',
                'A hospital (61–200 staff)',
                'A hospital group (200+ staff)',
              ] as $option)
                <option value="{{ $option }}" @selected(old('size') === $option)>{{ $option }}</option>
              @endforeach
            </select>
          </div>

          <div class="f span2">
            <label for="message">What would you like to know?</label>
            <textarea id="message" name="message" required maxlength="4000"
                      class="{{ $errors->has('message') ? 'bad' : '' }}"
                      placeholder="Which departments you run, what you use now, and anything you need answered before you would consider moving."
                      @error('message') aria-invalid="true" aria-describedby="message-e" @enderror>{{ old('message') }}</textarea>
            @error('message')
              <p class="err" id="message-e" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i> {{ $message }}</p>
            @else
              <p class="hint">A sentence or two is plenty. We read every one of these.</p>
            @enderror
          </div>
        </div>

        <button type="submit" class="btn lg" style="width:100%;justify-content:center;" data-busy="Sending…">
          <i class="fas fa-paper-plane" aria-hidden="true"></i> Send it
        </button>

        <p class="hint" style="text-align:center;margin-top:12px;">
          We use what you send here to reply to you and nothing else. See our
          <a href="{{ route('privacy') }}" class="link" style="color:var(--pri);">privacy policy</a>.
        </p>
      </form>
    </div>
  </div>
</section>

<section class="band-surface">
  <div class="wrap">
    <div class="sec-head"><h2>Or reach us directly</h2></div>
    <div class="grid" style="max-width:820px;margin:0 auto;">
      <a class="card" href="mailto:{{ config('mail.from.address') }}">
        <div class="ic" aria-hidden="true"><i class="fas fa-envelope"></i></div>
        <h3>Email</h3>
        <p>{{ config('mail.from.address') }}<br><span style="color:var(--tx3);">Usually answered within one business day.</span></p>
      </a>
      @if($demo)
        <a class="card" href="{{ route('test-login') }}">
          <div class="ic" aria-hidden="true"><i class="fas fa-flask"></i></div>
          <h3>Look around first</h3>
          <p>Open the demonstration hospital — a populated facility with real workflows in it. No sign-up, nothing to install.</p>
        </a>
      @endif
      <div class="card">
        <div class="ic" aria-hidden="true"><i class="fas fa-right-to-bracket"></i></div>
        <h3>Already a customer?</h3>
        <p><a class="link" style="color:var(--pri);font-weight:500;" href="{{ route('admin.login') }}">Sign in to your hospital</a> — support is reachable from inside your account, with your details already attached.</p>
      </div>
    </div>
  </div>
</section>

@endsection

@push('scripts')
<script>
  // One submission per press. Re-enabled on pageshow so the back button never
  // lands on a form that cannot be submitted again.
  document.querySelectorAll('form[action="{{ route('contact.send') }}"]').forEach((form) => {
    form.addEventListener('submit', () => {
      const button = form.querySelector('[type="submit"]');
      if (!button || button.disabled) return;
      button.innerHTML = '<i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> ' + button.dataset.busy;
      button.disabled = true;
      button.setAttribute('aria-busy', 'true');
    });
  });
  window.addEventListener('pageshow', () => {
    document.querySelectorAll('[type="submit"][aria-busy="true"]').forEach((b) => {
      b.disabled = false;
      b.removeAttribute('aria-busy');
    });
  });
</script>
@endpush
