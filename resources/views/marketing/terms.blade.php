@extends('layouts.marketing')
@section('title', 'Terms of Service — True-Doctor')
@section('desc', 'The terms your hospital agrees to when it subscribes to True-Doctor: what the service is, what we owe each other, and what happens if either side stops.')

@section('content')
<section>
  <div class="wrap page">
    <h1>Terms of Service</h1>
    <div class="updated">Last updated {{ date('F Y') }}</div>

    <div class="callout">
      <p>
        <strong>The short version.</strong> You pay per hospital for as long as you use it.
        Your data is yours and you can take it with you whenever you like. We keep the
        service running and tell you when we cannot. Either side can stop, and stopping
        does not cost you your records.
      </p>
    </div>

    <div class="toc">
      <b>On this page</b>
      <a href="#service">The service</a>
      <a href="#accounts">Accounts and access</a>
      <a href="#your-role">What your hospital is responsible for</a>
      <a href="#use">Acceptable use</a>
      <a href="#billing">Subscriptions and payment</a>
      <a href="#data">Your data</a>
      <a href="#availability">Availability and support</a>
      <a href="#ending">Ending the agreement</a>
      <a href="#liability">Liability</a>
      <a href="#changes">Changes, law and contact</a>
    </div>

    <h2 id="service">The service</h2>
    <p>
      True-Doctor is hospital management software provided as a subscription. Which
      modules are available depends on your plan. We improve and add to it over time;
      we will not remove core functionality your plan relies on without telling
      account administrators first and giving you time to react.
    </p>
    <p>
      It is a record-keeping and administration system. It does not practise medicine,
      does not make clinical decisions, and is not a medical device. Clinical judgement
      remains entirely with your practitioners.
    </p>

    <h2 id="accounts">Accounts and access</h2>
    <p>
      Accounts are for your hospital&rsquo;s own staff and are not for sharing. Each person
      who uses the system should have their own login — the audit trail is only worth
      anything if it names a person.
    </p>

    <h2 id="your-role">What your hospital is responsible for</h2>
    <ul>
      <li>The accuracy of what your staff enter. We store it faithfully; we cannot know whether it is right.</li>
      <li>Keeping credentials secure, and deactivating accounts when people leave.</li>
      <li>Giving each role only the access its job needs, using the controls provided.</li>
      <li>Meeting whatever your own regulator requires of you for record-keeping, consent and retention.</li>
    </ul>

    <h2 id="use">Acceptable use</h2>
    <ul>
      <li>Do not attempt to reach another hospital&rsquo;s data or work around access controls.</li>
      <li>Do not scrape, overload, or deliberately disrupt the service.</li>
      <li>Do not resell access or operate the service on behalf of a facility that has no subscription.</li>
      <li>Use it only for lawful healthcare operations.</li>
    </ul>
    <p>
      If you find a security problem, tell us — see the
      <a class="link" href="{{ route('security') }}">security page</a>. Reporting one in
      good faith is not a breach of these terms.
    </p>

    <h2 id="billing">Subscriptions and payment</h2>
    <p>
      Every plan begins with a 14-day free trial. No card is taken to start it, and
      nothing is charged if you do nothing at the end of it — the account simply
      pauses.
    </p>
    <p>
      After that, subscriptions are billed per hospital for the agreed cycle. A lapsed
      subscription keeps working through a short grace period rather than cutting off
      at midnight; after the grace period, access pauses until payment is brought
      current. Pausing never deletes anything.
    </p>
    <p>
      Prices may change. If they do, we will tell account administrators before the
      change reaches their next bill.
    </p>

    <h2 id="data">Your data</h2>
    <p>
      Your hospital owns the records it enters. You can export them at any time,
      including while an account is paused. We do not use your clinical data for any
      purpose other than providing the service to you — see the
      <a class="link" href="{{ route('privacy') }}">privacy policy</a>.
    </p>

    <h2 id="availability">Availability and support</h2>
    <p>
      We take reasonable measures to keep the service available and secure, and we
      tell you when something is wrong rather than waiting to be asked. We do not offer
      a contractual uptime guarantee, and this page will not pretend otherwise. Support
      is by email; response times depend on your plan.
    </p>

    <h2 id="ending">Ending the agreement</h2>
    <p>
      You can cancel at any time from inside your account; the subscription runs to the
      end of the period you have paid for. We may suspend or end an account for
      non-payment or for a serious breach of the acceptable-use section above — and
      other than in a case of deliberate abuse, we will give you notice and a chance to
      put it right. You get your data out either way.
    </p>

    <h2 id="liability">Liability</h2>
    <p>
      The service is provided as it is. To the extent the law allows, our liability
      arising from it is limited to the subscription fees you paid us in the twelve
      months before the claim. Nothing here limits liability that cannot lawfully be
      limited.
    </p>

    <h2 id="changes">Changes, law and contact</h2>
    <p>
      If these terms change materially, we will notify account administrators rather
      than quietly editing this page. These terms are governed by the laws of Uganda.
    </p>
    <p>
      Questions? Email
      <a class="link" href="mailto:{{ config('mail.from.address') }}">{{ config('mail.from.address') }}</a>.
    </p>
  </div>
</section>
@endsection
