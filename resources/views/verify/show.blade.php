@extends('layouts.patient')

@section('title', 'Verify a document')

@section('content')
  @if($checked && $row)
    <div class="pl-verdict is-ok">
      <i class="fas fa-circle-check" aria-hidden="true"></i>
      <h1 class="pl-title">Genuine document</h1>
    </div>
    <p class="pl-lead">
      This is a genuine {{ \Illuminate\Support\Str::lower($row->title) }} from {{ $brand->name() ?? 'this clinic' }},
      issued on {{ $row->issued_at->format('d M Y') }}@if($row->patient_initials) for a patient with initials {{ $row->patient_initials }}@endif.
    </p>
    <dl class="pl-facts">
      <dt>Document</dt><dd>{{ $row->title }}</dd>
      @if($row->reference)<dt>Number</dt><dd class="mono">{{ $row->reference }}</dd>@endif
      <dt>Issued</dt><dd>{{ $row->issued_at->format('d M Y') }}</dd>
      @if($row->patient_initials)<dt>Patient initials</dt><dd>{{ $row->patient_initials }}</dd>@endif
      <dt>Code</dt><dd class="mono">{{ \App\Models\DocumentVerification::display($row->code) }}</dd>
    </dl>
    <p class="pl-note">This page confirms the document was issued. It does not show what the document says: compare the number and date with the paper in front of you.</p>
  @else
    @if($checked)
      <div class="pl-verdict is-bad">
        <i class="fas fa-circle-xmark" aria-hidden="true"></i>
        <h1 class="pl-title">No document has this code</h1>
      </div>
      <p class="pl-lead">Check the code printed beside the QR, or ask the clinic that issued the document.</p>
    @else
      <h1 class="pl-title">Verify a document</h1>
      <p class="pl-lead">Type the code printed beside the QR code at the foot of the document.</p>
    @endif
    <form method="GET" action="{{ route('verify.form') }}" class="pl-form">
      <label class="tb-label" for="pl-code">Code</label>
      <input id="pl-code" name="code" class="tb-input mono" autocomplete="off" maxlength="12" placeholder="K7QM-4XPA" value="{{ old('code') }}" required>
      @error('code')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
      <button type="submit" class="btn-tb btn-tb-primary pl-open">Check</button>
    </form>
  @endif
@endsection
