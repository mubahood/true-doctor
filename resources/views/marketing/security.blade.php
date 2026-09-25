@extends('layouts.marketing')
@section('title', 'Security — True-Doctor')
@section('desc', 'How patient data is isolated, encrypted, logged and access-controlled — and what we do not claim. Written for the person who has to sign off on it.')

@section('content')

<section class="hero compact">
  <div class="wrap">
    <div class="eyebrow">Security</div>
    <h1>Treated as a healthcare data system.</h1>
    <p class="lead">
      Patient information is sensitive by default. The controls below are part of
      how True-Doctor is built rather than something added once somebody asked —
      and the last section says plainly what we do not yet claim.
    </p>
  </div>
</section>

<section class="band-surface" style="padding-top:48px;">
  <div class="wrap">
    <div class="sec-head">
      <h2>The controls</h2>
      <p>Each of these is enforced in code, not in a policy document.</p>
    </div>
    <div class="grid two">
      @foreach([
        ['fa-shield-halved', 'One hospital cannot see another', 'Every record carries the hospital it belongs to, and every query is filtered by a scope applied at the database layer — not by a condition each developer has to remember. A dedicated test suite exists solely to try to read across that boundary and fail.'],
        ['fa-lock', 'Encryption at rest', 'Card numbers, bank details and other protected fields are encrypted in the database, so a copy of the data on its own does not give them up. Card numbers are additionally stored as a hash for lookup, never in the clear.'],
        ['fa-list-check', 'Changes are recorded', 'Who changed a patient or visit record, when, and what the value was before. Every workflow — a visit&rsquo;s stages, a claim&rsquo;s statuses, an order&rsquo;s progress, an appointment&rsquo;s attendance — keeps an append-only history that is never edited in place. Read access is not currently logged; see what we do not claim, below.'],
        ['fa-user-shield', 'Role-based access', 'Nine roles, each with its own menu, screens and fields. A nurse cannot record a diagnosis; a cashier cannot open clinical notes. The same rules are enforced in the web panel and in the API, from one definition.'],
        ['fa-key', 'No default passwords', 'The first administrator account is created with a random password printed once to the console and a forced change on first sign-in. Staff are invited the same way. There is no shipped password to look up.'],
        ['fa-plug', 'One hardened API', 'A single token-authenticated API — versioned, rate-limited, origin-locked, with one response envelope. No second authentication path and no legacy bypass, because the one that gets less attention is the one somebody finds.'],
        ['fa-money-check-dollar', 'Money cannot half-move', 'Every payment, refund and stock movement runs inside a database transaction with the affected rows locked. A balance and the ledger that explains it can never disagree because something failed halfway.'],
        ['fa-mobile-screen', 'Offline, without giving it away', 'A device holds only what that member of staff may already see, and no password is ever stored on it. The server re-checks every permission when the work is sent back — the device is never treated as trusted.'],
      ] as [$icon, $title, $body])
        <div class="card">
          <div class="ic" aria-hidden="true"><i class="fas {{ $icon }}"></i></div>
          <h3>{!! $title !!}</h3>
          <p>{!! $body !!}</p>
        </div>
      @endforeach
    </div>
  </div>
</section>

{{-- ── The honest section ─────────────────────────────────────────────
     A security page that claims everything is a security page nobody
     technical believes. Saying what is not yet true is what makes the rest
     of the page worth reading. --}}
<section>
  <div class="wrap narrow">
    <div class="sec-head">
      <h2>What we do not claim</h2>
      <p>Because a page that claims everything is worth nothing.</p>
    </div>
    <div class="faq">
      @foreach([
        ['We are not certified against HIPAA, ISO 27001 or SOC 2.', 'No audit has been carried out and we do not describe ourselves as compliant with any of them. The controls on this page are real and we are happy to walk your team through them; a certificate is a different thing and we do not have one.'],
        ['We do not hold your data in your country unless you ask.', 'Where the application and its backups are hosted depends on the deployment. If data residency matters to your facility, raise it before you sign up rather than after.'],
        ['Availability is not guaranteed by a contract.', 'We take reasonable measures to keep the service up and we tell you when it is not. There is no uptime SLA with money attached to it, and we will not pretend otherwise on a marketing page.'],
        ['Two-factor authentication is not yet on every account.', 'It is planned for administrator accounts and is not shipped. Until it is, the protections that exist are a forced password change, per-attempt rate limiting on sign-in, and a session you can revoke.'],
        ['We do not log who READ a record.', 'Changes are recorded with who made them and what the value was before, and every workflow keeps an append-only status history. But a member of staff opening a patient record and closing it again leaves no entry. If your regulator requires read auditing, tell us before you sign up rather than after — it is a real gap, not a wording choice.'],
      ] as [$q, $a])
        <details>
          <summary>{!! $q !!}</summary>
          <div class="a"><p>{!! $a !!}</p></div>
        </details>
      @endforeach
    </div>
  </div>
</section>

<section class="band-surface">
  <div class="wrap narrow">
    <div class="sec-head">
      <h2>Reporting a vulnerability</h2>
      <p>If you have found something, we would much rather hear it from you.</p>
    </div>
    <div class="page">
      <div class="callout">
        <p>
          Email <a class="link" href="mailto:{{ config('mail.from.address') }}">{{ config('mail.from.address') }}</a>
          with enough detail to reproduce it. We will acknowledge within two business days
          and tell you what we are doing about it. Please do not test against a real
          hospital&rsquo;s data — ask us and we will point you at an instance you can hit.
        </p>
      </div>
      <p>
        We will not pursue anybody who reports a genuine issue in good faith, keeps the
        detail private until it is fixed, and does not access, change or remove data that
        is not theirs.
      </p>
    </div>
  </div>
</section>

<section class="band">
  <div class="wrap">
    <h2>Have a compliance question?</h2>
    <p>We will walk your team through exactly how patient data is stored, isolated and audited — including the parts that are still on the list.</p>
    <a href="{{ route('contact') }}" class="btn lg">Ask us <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
  </div>
</section>

@endsection
