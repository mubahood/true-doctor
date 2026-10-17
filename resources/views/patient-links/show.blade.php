@extends('layouts.patient')

@section('title', $link?->title ?? 'Document')

@section('content')
  @php($phone = $brand->contact()['phone'])

  @if($state === 'open')
    <h1 class="pl-title">{{ $link->title }}</h1>
    <p class="pl-lead">
      {{ $firstName ? 'Hello '.$firstName.', here' : 'Here' }} is your {{ \Illuminate\Support\Str::lower($link->title) }} from {{ $brand->name() ?? 'the clinic' }}.
    </p>
    <dl class="pl-facts">
      @if($link->reference)<dt>Number</dt><dd class="mono">{{ $link->reference }}</dd>@endif
      <dt>Sent</dt><dd>{{ $link->created_at?->format('d M Y') }}</dd>
      <dt>Link works until</dt><dd>{{ $link->expires_at->format('d M Y H:i') }}</dd>
    </dl>
    {{-- A document opens in its own tab, like everything this system prints. --}}
    <a class="btn-tb btn-tb-primary pl-open" href="{{ route('patient-link.document', ['token' => $token]) }}" target="_blank" rel="noopener">
      <i class="fas fa-file-pdf" aria-hidden="true"></i> Open the document
    </a>
    <p class="pl-note">Keep this to yourself: anyone with the link and your code can open it.</p>

  @elseif($state === 'ask')
    <h1 class="pl-title">{{ $link->title }}</h1>
    <p class="pl-lead">{{ $brand->name() ?? 'The clinic' }} has sent you a document. To open it, type the code from your message, or your date of birth.</p>
    <form method="POST" action="{{ route('patient-link.unlock', ['token' => $token]) }}" class="pl-form">
      @csrf
      <label class="tb-label" for="pl-answer">Code or date of birth (DD/MM/YYYY)</label>
      <input id="pl-answer" name="answer" class="tb-input" autocomplete="off" inputmode="text" maxlength="40" required autofocus
             aria-describedby="pl-answer-help">
      @error('answer')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
      <div id="pl-answer-help" class="pl-note">After {{ \App\Models\PatientLink::MAX_ATTEMPTS }} wrong tries the link locks. {{ $triesLeft }} left.</div>
      <button type="submit" class="btn-tb btn-tb-primary pl-open">Open</button>
    </form>

  @else
    <h1 class="pl-title">
      @switch($state)
        @case('expired') This link has expired @break
        @case('revoked') This link has been withdrawn @break
        @case('locked') This link is locked @break
        @default This link does not work
      @endswitch
    </h1>
    <p class="pl-lead">
      @if($state === 'locked')
        Too many wrong codes were tried, so the link was locked to keep your information safe.
      @elseif($state === 'missing')
        Check that the whole link was copied from your message.
      @endif
      @if($brand->name())
        Please contact {{ $brand->name() }}@if($phone) on <a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}">{{ $phone }}</a>@endif and they can send you a new one.
      @endif
    </p>
  @endif
@endsection
