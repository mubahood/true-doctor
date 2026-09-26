@extends('layouts.marketing')
@section('title', 'Privacy Policy — True-Doctor')
@section('desc', 'How True-Doctor handles hospital, staff and patient information — who controls what, what is stored, and what you can ask for.')

@section('content')
<section>
  <div class="wrap page">
    <h1>Privacy Policy</h1>
    <div class="updated">Last updated {{ date('F Y') }}</div>

    <div class="callout">
      <p>
        <strong>The short version.</strong> Your hospital owns the patient records it
        enters; we hold them on your behalf and never use them for anything but running
        the service for you. We do not sell data, we do not advertise, and we do not
        share records with another hospital under any circumstances.
      </p>
    </div>

    <div class="toc">
      <b>On this page</b>
      <a href="#roles">Who controls what</a>
      <a href="#hospital-data">Data your hospital holds</a>
      <a href="#staff">Staff accounts</a>
      <a href="#visitors">Visitors to this website</a>
      <a href="#protection">How data is protected</a>
      <a href="#processors">Who else touches it</a>
      <a href="#retention">Retention, export and erasure</a>
      <a href="#rights">If you are a patient</a>
      <a href="#changes">Changes and contact</a>
    </div>

    <h2 id="roles">Who controls what</h2>
    <p>
      True-Doctor is hospital management software sold to healthcare facilities. Two
      different relationships run through it, and they are worth separating because
      your rights differ between them.
    </p>
    <ul>
      <li><strong>Your hospital is the controller</strong> of the patient and clinical data it enters. It decides what is collected, why, and for how long. We are its processor, acting on its instructions.</li>
      <li><strong>We are the controller</strong> of the account itself — the hospital&rsquo;s subscription, its staff logins and the billing relationship between us.</li>
    </ul>

    <h2 id="hospital-data">Data your hospital holds</h2>
    <p>
      Hospitals use True-Doctor to record patients, appointments, visits, vitals,
      diagnoses, prescriptions, lab and radiology results, dispensing, admissions,
      invoices and payments. That information belongs to the hospital that entered it
      and is kept strictly separate from every other hospital on the platform.
    </p>
    <p>
      We do not read it, mine it, train anything on it, or use it to build features.
      Our staff access it only when your hospital asks us to help with a specific
      problem, and that access is logged like any other.
    </p>

    <h2 id="staff">Staff accounts</h2>
    <p>
      For each member of staff we store the name, email address, role and activity
      needed to run the login and the audit trail. Accounts are invite-based with a
      forced password change on first sign-in. Passwords are stored only as a hash
      and cannot be recovered by us or by anybody else.
    </p>
    <p>
      If a member of staff chooses &ldquo;keep me signed in&rdquo;, their browser is given a
      long-lived token that identifies that one session on that one device. Signing
      out invalidates it everywhere.
    </p>

    <h2 id="visitors">Visitors to this website</h2>
    <p>
      These public pages set no third-party advertising or analytics cookies, and load
      no tracking scripts. Three cookies of our own may be set:
    </p>
    <ul>
      <li>A session cookie, needed to protect forms against cross-site request forgery.</li>
      <li>A currency preference, if you use the switch in the footer, so the site remembers how you want prices shown.</li>
      <li>
        A visitor cookie, kept for 30 days, holding a random identifier. It lets us see
        which advertisement or link brought a visitor, which of our pages they opened,
        and whether they went on to start a trial — so we know which of our adverts are
        worth paying for.
      </li>
    </ul>
    <p>
      With that identifier we record, on our own servers: the pages opened and when;
      the campaign details and click identifier in the link you followed (for example
      from a Google advert); the website that referred you; the type of device, browser
      and operating system; and the country your connection appears to be in. We do not
      store your IP address — only a one-way keyed fingerprint of it that cannot be
      turned back into the address. None of this is shared with anybody, except that
      when a visit leads to a sign-up or a payment, the advert&rsquo;s click identifier is
      reported back to Google so it can tell which advert worked. Records of visits
      that did not lead to a sign-up are deleted after about thirteen months.
    </p>
    <p>
      To decide which currency to quote in before you have chosen, the site looks at
      the country your network connection appears to be in — from a header your
      network provider or our content delivery network supplies, and otherwise from
      your browser&rsquo;s language setting. You can override it with the switch in the footer.
    </p>
    <p>
      Our servers keep ordinary web logs — IP address, page, time — for security and
      troubleshooting, and discard them on a rolling basis.
    </p>

    <h2 id="protection">How data is protected</h2>
    <ul>
      <li>Every record carries its hospital and is filtered at the database layer, not by a condition somebody has to remember to write.</li>
      <li>Sensitive fields — card numbers, bank details and protected clinical data — are encrypted at rest.</li>
      <li>Access is role-based: each role sees only the records and fields its job needs.</li>
      <li>Changes to patient and visit records are logged with who made them and when, and every workflow keeps an append-only status history. Read access is not currently logged.</li>
      <li>Credentials and third-party keys live only in server configuration, never in the code.</li>
    </ul>
    <p>See the <a class="link" href="{{ route('security') }}">security page</a> for the detail, including what we do not claim.</p>

    <h2 id="processors">Who else touches it</h2>
    <p>
      Running the service needs a small number of suppliers: a hosting provider, an
      email delivery service for notifications, and a payment provider for
      subscriptions and for patient payments taken online. Each receives only what it
      needs to do its job — a payment provider sees an amount and a reference, not a
      clinical record. We do not share hospital data with anybody else.
    </p>

    <h2 id="retention">Retention, export and erasure</h2>
    <p>
      Your hospital decides how long to keep its records, subject to whatever its own
      regulator requires. You can export your data at any time, including after a
      subscription lapses. If you close your account and ask us to erase what remains,
      we will — and we will tell you when it is done, including from backups as those
      rotate out.
    </p>

    <h2 id="rights">If you are a patient</h2>
    <p>
      Your records belong to the hospital that treated you, not to us. To see, correct
      or ask about anything held about you, contact that hospital directly — they can
      act on it immediately, and we cannot act on it without them.
    </p>

    <h2 id="changes">Changes and contact</h2>
    <p>
      If this policy changes in a way that affects how hospital data is handled, we
      will tell account administrators rather than quietly editing the page.
    </p>
    <p>
      Questions about this policy? Email
      <a class="link" href="mailto:{{ config('mail.from.address') }}">{{ config('mail.from.address') }}</a>.
    </p>
  </div>
</section>
@endsection
