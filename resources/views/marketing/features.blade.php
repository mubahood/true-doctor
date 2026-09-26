@extends('layouts.marketing')
@section('title', 'Product — True-Doctor')
@section('desc', 'Every module a hospital needs: patients, scheduling, visits, pharmacy, lab, radiology, inpatient care, billing and reporting — one connected system that also works offline.')

@section('content')

<section class="hero compact">
  <div class="wrap">
    <div class="eyebrow">Product</div>
    <h1>Every part of the hospital, connected.</h1>
    <p class="lead">
      One record follows the patient from the front desk through the clinic, the
      pharmacy and the finance office. Nothing is re-keyed, and there is nothing
      to reconcile at the end of the day.
    </p>
  </div>
</section>

{{-- ── Grouped by who uses it ─────────────────────────────────────────
     Twelve identical cards in a row is a list of nouns. Grouping them by the
     desk that opens them tells somebody reading which parts are theirs. --}}
@php
  $groups = [
    [
      'eyebrow' => 'Front desk',
      'title' => 'Getting people in and seen',
      'blurb' => 'What reception and records work with all day.',
      'cards' => [
        ['fa-user-injured', 'Patient records', 'Generated patient numbers, demographics, medical history, allergies and chronic conditions, dependents and guardians, documents and photographs.', ['Duplicate check on registration', 'QR ID cards, printable', 'Next of kin and consent']],
        ['fa-calendar-check', 'Appointments', 'Doctor availability windows, booking with conflict detection, a live check-in queue, and reminders before the day.', ['No double-booking a doctor or a room', 'No-show and cancellation tracking', 'Follow-ups booked from inside a visit']],
        ['fa-id-card', 'Patient cards', 'Optional prepaid and insurer member cards with a proper ledger — every top-up and debit recorded, and a family can share one balance.', ['Card numbers encrypted at rest', 'A parent&rsquo;s card can pay for a child', 'Credit limits per card or per insurer']],
      ],
    ],
    [
      'eyebrow' => 'Clinical',
      'title' => 'The consultation and everything it raises',
      'blurb' => 'What doctors, nurses and the benches work with.',
      'cards' => [
        ['fa-stethoscope', 'Visits', 'Vitals and BMI, complaints, examination, diagnosis and plan — on one enforced state machine, so a visit cannot reach billing with work still open on it.', ['Each role sees its own view of the same visit', 'Full status history, never edited', 'The gate to the next stage is shown, with the reason it is shut']],
        ['fa-prescription-bottle-medical', 'Prescriptions &amp; dosing', 'Structured prescriptions that generate a per-day dosing schedule, and a record of each dose actually given.', ['Morning / afternoon / evening / night slots', 'Administration tracked on the ward', 'Missed doses visible, not inferred']],
        ['fa-vial', 'Laboratory &amp; radiology', 'Ordered from inside the visit, worked on the bench through collected → processing → reported, results captured with attachments, and a printable report.', ['Reference ranges and abnormal flags', 'The charge is raised when the order is', 'Report PDFs on the hospital&rsquo;s own letterhead']],
        ['fa-bed', 'Inpatient care', 'Wards, bed occupancy, admissions as an order on the visit, transfers between beds, nursing rounds and discharge summaries.', ['Each night billed the next morning, at that night&rsquo;s rate', 'A live occupancy board', 'Reprice a ward and every bed in it follows']],
      ],
    ],
    [
      'eyebrow' => 'Money &amp; stock',
      'title' => 'Getting paid, and knowing what is on the shelf',
      'blurb' => 'What the pharmacy, the cashier and the accountant work with.',
      'cards' => [
        ['fa-pills', 'Pharmacy &amp; stock', 'Inventory with live valuation, an append-only movement ledger, dispensing against a prescription, and alerts before something runs out or expires.', ['Stock moves in the same transaction as the sale', 'Low-stock and 90-day expiry alerts', 'Adjustments are recorded with a reason']],
        ['fa-file-invoice-dollar', 'Billing &amp; payments', 'Price lists, invoices built from what was actually done, and cash, mobile money, card, bank or prepaid-card payments — each inside a database transaction with row locking.', ['A visit cannot close owing money', 'Receipts and invoices as PDFs', 'Financial years that can be closed to stop back-posting']],
        ['fa-shield-heart', 'Insurance', 'Providers, patient coverage, a claims lifecycle with full status history, and an insurer float that member cards draw against.', ['Claim statuses from draft to paid', 'A usage statement per insurer, printable', 'Members can be cleared in bulk']],
        ['fa-chart-line', 'Reports', 'Revenue by method and by day, outstanding debt aged into buckets, inpatient nights by ward, doctor activity, service revenue, stock valuation and occupancy.', ['Any date range, shareable as a link', 'The whole report as a PDF for a board', 'Figures read from the records, never cached stale']],
      ],
    ],
    [
      'eyebrow' => 'Everywhere',
      'title' => 'The parts that hold it together',
      'blurb' => 'What nobody thinks about until it is missing.',
      'cards' => [
        ['fa-wifi', 'Field Mode', 'A copy of what that member of staff may see, on their device. Registrations, vitals, notes and results captured offline and sent when the line comes back.', ['Nothing unsent is ever deleted automatically', 'A conflict is shown to a person, not guessed', 'The screen always says how much is waiting']],
        ['fa-user-shield', 'Roles &amp; permissions', 'Nine staff roles, each with its own menu, its own screens and its own fields — enforced in the app and the API identically.', ['A nurse cannot record a diagnosis', 'A cashier cannot read clinical notes', 'Every role tested, not asserted']],
        ['fa-bell', 'Notifications', 'Appointment reminders, result-ready notices and low-stock alerts, by email, SMS or push.', ['Per-hospital sender details', 'Quiet hours respected', 'Failures retried, not dropped']],
        ['fa-print', 'Documents', 'Invoices, receipts, lab and radiology reports, discharge summaries, ID cards, insurer statements and board reports — all on your own letterhead.', ['Your logo, address and licence number', 'Opens in a tab, not a download', 'One house style across every document']],
      ],
    ],
  ];
@endphp

@foreach($groups as $i => $group)
  <section class="{{ $i % 2 === 0 ? 'band-surface' : '' }}" @if($i === 0) style="padding-top:48px;" @endif>
    <div class="wrap">
      <div class="sec-head">
        <div class="eyebrow">{!! $group['eyebrow'] !!}</div>
        <h2 style="margin-top:10px;">{!! $group['title'] !!}</h2>
        <p>{!! $group['blurb'] !!}</p>
      </div>
      <div class="grid two">
        @foreach($group['cards'] as [$icon, $title, $body, $points])
          {{-- The id an ad sitelink opens at: /?section=modules&module=pharmacy
               becomes /features#pharmacy, which the browser scrolls to with
               no JavaScript at all. The highlight is decoration on top. --}}
          @php($anchor = \App\Support\LandingIntent::anchorFor($title))
          <div class="card" @if($anchor) id="{{ $anchor }}" @endif>
            <div class="ic" aria-hidden="true"><i class="fas {{ $icon }}"></i></div>
            <h3>{!! $title !!}</h3>
            <p>{!! $body !!}</p>
            <ul>@foreach($points as $point)<li>{!! $point !!}</li>@endforeach</ul>
          </div>
        @endforeach
      </div>
    </div>
  </section>
@endforeach

{{-- ── How it is built ────────────────────────────────────────────────
     A hospital buying clinical software is buying the promise that the
     numbers are right. This says what that rests on, in terms somebody
     non-technical can still weigh. --}}
<section>
  <div class="wrap">
    <div class="sec-head">
      <h2>Why the numbers can be trusted</h2>
      <p>Correctness in a hospital system is not a feature you add later.</p>
    </div>
    <div class="grid">
      @foreach([
        ['fa-lock', 'Money never half-moves', 'Every payment, refund, dispensation and stock adjustment runs inside a database transaction with the rows locked. A crash halfway through leaves nothing behind — either all of it happened or none of it did.'],
        ['fa-calculator', 'No floating-point money', 'Amounts are held and added as exact decimals, not as floats. A hundred invoices add up to the same total whichever order you add them in.'],
        ['fa-diagram-project', 'States cannot be skipped', 'A visit, an order, a claim and an admission each move through a defined set of states. An appointment cannot be Completed without a record of what was done at it.'],
        ['fa-vials', 'Tested, continuously', 'Around nineteen hundred automated tests run against every change — including a suite whose only job is to prove one hospital cannot read another&rsquo;s records.'],
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

<section class="band-surface band">
  <div class="wrap">
    <h2>See it with data in it</h2>
    <p>The demonstration hospital is a real, populated facility — a busy register, a full ward, open visits at every stage and three months of billing behind it.</p>
    <div class="ctas">
      @if($demo)<a href="{{ route('test-login') }}" class="btn lg">Open the demo <i class="fas fa-arrow-right" aria-hidden="true"></i></a>@endif
      <a href="{{ route('pricing') }}" class="btn {{ $demo ? 'ghost' : '' }} lg">See pricing</a>
      <a href="{{ route('contact') }}" class="btn ghost lg">Talk to us</a>
    </div>
  </div>
</section>

@endsection
