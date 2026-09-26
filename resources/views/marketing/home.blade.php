@extends('layouts.marketing')
@section('title', 'True-Doctor — Hospital management software for East Africa')
@section('desc', 'One system for patients, appointments, visits, pharmacy, lab, radiology, inpatient care and billing. Per-hospital data isolation, a full audit trail, and it keeps working when the network does not.')

@section('content')

{{-- ── Hero ──────────────────────────────────────────────────────────
     The patterned background is in the stylesheet (.hero::before): a tiled
     clinical motif under a soft light, so the type never competes with the
     texture it sits on. --}}
<section class="hero">
  <div class="wrap">
    <div class="eyebrow">Cloud HMS for clinics and hospitals</div>
    <h1>The <b>hospital management system</b><br>that replaces your paper registers.</h1>
    <p class="lead">
      Clinic management software that carries a patient from the front desk to the
      cashier: EMR and patient records, appointments, pharmacy, lab and radiology,
      inpatient wards and billing. One subscription, one login, per hospital — and
      it keeps working when the network does not.
    </p>
    <div class="ctas">
      @auth
        <a href="{{ route('admin.dashboard') }}" class="btn lg">Go to your dashboard <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
        <a href="{{ route('features') }}" class="btn ghost lg">See the modules</a>
      @else
        <a href="{{ route('register') }}" class="btn lg">Start a free trial <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
        @if($demo)
          <a href="{{ route('test-login') }}" class="btn plain lg">Open the live demo</a>
        @else
          <a href="{{ route('features') }}" class="btn plain lg">See the modules</a>
        @endif
      @endauth
    </div>
    <div class="trust-row">
      <span><i class="fas fa-shield-halved" aria-hidden="true"></i> Per-hospital data isolation</span>
      <span><i class="fas fa-lock" aria-hidden="true"></i> Encrypted sensitive records</span>
      <span><i class="fas fa-list-check" aria-hidden="true"></i> Every change logged</span>
    </div>

    <x-site.product-shot />
  </div>
</section>

{{-- ── The journey ────────────────────────────────────────────────────
     A workflow in the order a patient actually meets it, rather than an
     alphabetised list of modules. What a hospital is buying is the joins
     between these, not the boxes. --}}
<section class="band-surface">
  <div class="wrap">
    <div class="sec-head">
      <h2>One record, from the gate to the cashier</h2>
      <p>Each step hands the next one everything it needs. Nothing is re-typed, and nothing is reconciled at the end of the day.</p>
    </div>

    <div class="flow">
      @foreach([
        ['fa-user-plus', 'Reception', 'Registration &amp; triage', 'The patient is found or registered, given a number and a QR card, and put on the queue. Vitals are taken and attached to the visit before the doctor opens it.'],
        ['fa-stethoscope', 'Consultation', 'The doctor', 'Complaints, examination, diagnosis and plan on one screen — with the patient&rsquo;s allergies, chronic conditions and last visit already in front of them.'],
        ['fa-vial', 'Lab &amp; imaging', 'Orders and results', 'Ordered from inside the visit, worked on the bench, and the result comes back to the same visit. The charge is raised the moment the order is.'],
        ['fa-pills', 'Pharmacy', 'Dispensing', 'Dispensed against the prescription, off the shelf, with the stock ledger moving in the same transaction. Low stock and near-expiry are flagged before they bite.'],
        ['fa-bed', 'Ward', 'Admission &amp; stay', 'A stay is an order on the visit. Each night in the bed is billed the following morning at the rate in force that night — not estimated at discharge.'],
        ['fa-file-invoice-dollar', 'Cashier', 'The bill', 'Everything charged along the way is already on the invoice. Cash, mobile money, card or an insurer&rsquo;s claim — and the visit cannot close owing money.'],
      ] as $i => [$icon, $who, $title, $body])
        <div class="flow-row">
          <span class="st" aria-hidden="true"><i class="fas {{ $icon }}"></i></span>
          <div>
            <h3>{!! $title !!}</h3>
            <div class="who">{!! $who !!}</div>
          </div>
          <p>{!! $body !!}</p>
        </div>
      @endforeach
    </div>
  </div>
</section>

{{-- ── Modules ─────────────────────────────────────────────────────── --}}
<section>
  <div class="wrap">
    <div class="sec-head">
      <h2>Everything a hospital runs on</h2>
      <p>Switched on by plan — you are never paying for a ward module in a two-room clinic.</p>
    </div>
    <div class="grid">
      @foreach([
        ['fa-user-injured', 'Patients', 'Registration, medical history, dependents, documents, generated patient numbers and QR ID cards.'],
        ['fa-calendar-check', 'Appointments', 'Doctor availability, booking with conflict detection, a check-in queue and reminders.'],
        ['fa-stethoscope', 'Visits', 'Vitals, diagnosis and prescriptions on an enforced clinical state machine — a visit cannot skip a step.'],
        ['fa-pills', 'Pharmacy', 'Stock with valuation, dispensing, an append-only movement ledger, low-stock and expiry alerts.'],
        ['fa-vial', 'Lab &amp; radiology', 'Order from the visit, capture results and attachments, print a report, bill automatically.'],
        ['fa-bed', 'Inpatient', 'Wards, beds, admissions, transfers, nursing rounds and per-night bed billing.'],
        ['fa-file-invoice-dollar', 'Billing', 'Price lists, invoices, cash, mobile money, card and insurance — every posting inside a transaction.'],
        ['fa-chart-line', 'Reports', 'Revenue, outstanding debt by age, bed occupancy, doctor activity and stock valuation — printable.'],
      ] as [$icon, $title, $body])
        <div class="card">
          <div class="ic" aria-hidden="true"><i class="fas {{ $icon }}"></i></div>
          <h3>{!! $title !!}</h3>
          <p>{!! $body !!}</p>
        </div>
      @endforeach
    </div>
    <p style="text-align:center;margin-top:28px;">
      <a href="{{ route('features') }}" class="btn ghost">See what each module does <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
    </p>
  </div>
</section>

{{-- ── The differentiator ─────────────────────────────────────────────
     Offline is the thing this system has that a generic HMS does not, and
     it is worth a section of its own rather than a bullet in a grid. --}}
<section class="band-surface">
  <div class="wrap">
    <div class="grid two" style="align-items:center;gap:40px;">
      <div>
        <div class="eyebrow">Field Mode</div>
        <h2 style="margin:12px 0 14px;">It keeps working when the network does not.</h2>
        <p style="color:var(--tx2);font-size:14.5px;margin-bottom:14px;">
          An outreach clinic, a ward on a bad line, a power cut at the exchange — the
          work does not stop for any of them. Field Mode runs from a copy of the
          records on the device, takes registrations, vitals, notes and results
          offline, and sends them when the connection returns.
        </p>
        <ul style="list-style:none;display:flex;flex-direction:column;gap:9px;">
          @foreach([
            'Nothing is lost and nothing is silently overwritten — a conflict is shown to a person, not guessed at.',
            'The device holds only what that member of staff is allowed to see.',
            'Unsent work is never deleted automatically, and the screen always says how much is waiting.',
          ] as $point)
            <li style="display:flex;gap:10px;font-size:13.5px;color:var(--tx2);">
              <i class="fas fa-check" style="color:var(--ok);margin-top:4px;" aria-hidden="true"></i>
              <span>{{ $point }}</span>
            </li>
          @endforeach
        </ul>
      </div>
      <div class="stats">
        <div class="stat"><b>0</b><span>records lost to a dropped connection</span></div>
        <div class="stat"><b>1</b><span>sign-in for every module</span></div>
        <div class="stat"><b>14</b><span>days free, no card</span></div>
        <div class="stat"><b>9</b><span>staff roles, each with its own view</span></div>
      </div>
    </div>
  </div>
</section>

{{-- ── Getting started ─────────────────────────────────────────────── --}}
<section>
  <div class="wrap">
    <div class="sec-head"><h2>Live in three steps</h2><p>No server to run, no spreadsheet to reconcile, no consultant to book.</p></div>
    <div class="steps">
      <div class="step">
        <div class="n" aria-hidden="true">1</div>
        <h3>Create your hospital</h3>
        <p>Name, plan, and you are in — on a 14-day trial with every module switched on and no card taken.</p>
      </div>
      <div class="step">
        <div class="n" aria-hidden="true">2</div>
        <h3>Follow the checklist</h3>
        <p>A guided setup fills in your departments, wards and beds, price list and staff accounts. Twenty minutes, not a project.</p>
      </div>
      <div class="step">
        <div class="n" aria-hidden="true">3</div>
        <h3>Register the first patient</h3>
        <p>From check-in to prescription to payment — one connected record, fully audited, from the first day.</p>
      </div>
    </div>
  </div>
</section>

{{-- ── FAQ ─────────────────────────────────────────────────────────── --}}
<section class="band-surface">
  <div class="wrap">
    <div class="sec-head"><h2>Questions we get asked first</h2></div>
    <x-site.faq :items="[
      ['Can another hospital see our patients?', 'No. Every record carries its hospital and is filtered by a scope applied at the database layer, not by a condition somebody has to remember to write. An automated isolation suite runs against that on every change.'],
      ['What happens to our data if we stop paying?', 'A lapsed subscription keeps working through a grace period, then access pauses. Nothing is deleted, and you can export your records at any point. The data is yours.'],
      ['Do we have to move our old records in first?', 'No. Most hospitals start with new patients from day one and let the old register retire naturally. If you do want history moved, talk to us about it before you sign up.'],
      ['Does it work on a phone?', 'Yes. Every screen is built for a narrow window as well as a wide one, and Field Mode is designed for a tablet on a ward round.'],
      ['How many staff accounts do we get?', 'It depends on the plan, and it is never per seat — you are not charged for adding the night nurse. See the pricing page for the limits on each.'],
      ['Which payment methods can we take from patients?', 'Cash, mobile money, card, bank transfer, a patient&rsquo;s prepaid card, and an insurer&rsquo;s claim against an invoice. Each is recorded against the invoice it settles.'],
    ]" />
  </div>
</section>

{{-- ── Close ───────────────────────────────────────────────────────── --}}
<section class="band">
  <div class="wrap">
    <h2>One hospital, one account, full isolation</h2>
    <p>Start on a 14-day trial with every module switched on. No card, no call, and nothing to install.</p>
    <div class="ctas">
      @auth
        <a href="{{ route('admin.dashboard') }}" class="btn lg">Go to your dashboard <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
      @else
        <a href="{{ route('register') }}" class="btn lg">Start a free trial <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
      @endauth
      <a href="{{ route('contact') }}" class="btn ghost lg">Talk to us first</a>
    </div>
  </div>
</section>

@endsection
