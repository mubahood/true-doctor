{{--
  The top of every email: whose system sent it. A hospital that has set its
  brand sends in its own colour with its own logo; otherwise True-Doctor's.
  Email clients ignore stylesheets, so everything is inline and table-based.
  Expects $user (the recipient) and $subject.
--}}
@php
  $mailBrand = \App\Support\HospitalBrand::forUser($user ?? null);
  $mailOwn = $mailBrand->isSet();
  $mailColour = $mailBrand->colour() ?? '#0A6EBD';
  $mailSoft = ($mailBrand->palette()['soft'] ?? null) ?? '#E8F1FB';
  $mailOrg = $mailBrand->name();
  // The same choice the sign-in card makes (HospitalBrand::wideUrl).
  $mailWideUrl = $mailOwn ? $mailBrand->wideUrl() : null;
  $mailLogo = $mailWideUrl ?? ($mailOwn ? $mailBrand->markUrl() : null);
  $mailWide = $mailWideUrl !== null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>{{ $subject ?? ($mailOrg ?? 'True-Doctor') }}</title>
</head>
<body style="margin:0;padding:0;background:#F5F7FA;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#F5F7FA;">
<tr><td align="center" style="padding:36px 16px 24px;">

  <table width="600" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px;width:100%;background:#FFFFFF;border:1px solid #E2E8F0;border-top:4px solid {{ $mailColour }};">

    {{-- ── Whose email this is ── --}}
    <tr>
      <td align="center" style="padding:28px 32px 22px;border-bottom:1px solid #EEF2F7;">
        @if($mailLogo)
          <img src="{{ $mailLogo }}" alt="{{ $mailOrg }}" @if($mailWide) width="230" @else width="64" @endif
               style="display:block;width:auto;height:auto;max-width:{{ $mailWide ? '230px' : '64px' }};max-height:{{ $mailWide ? '96px' : '64px' }};border:0;">
          @unless($mailWide)
            <p style="font-size:17px;font-weight:600;color:#111C2E;margin:10px 0 0;">{{ $mailOrg }}</p>
          @endunless
        @elseif($mailOwn)
          {{-- A colour but no logo: the hospital's name heads its own email. --}}
          <p style="font-size:20px;font-weight:600;color:{{ $mailColour }};margin:0;">{{ $mailOrg }}</p>
        @else
          <img src="{{ asset('images/logo-horizontal.png') }}" alt="True-Doctor" width="170" style="display:block;height:auto;border:0;">
          @if($mailOrg)
            <p style="font-size:13px;font-weight:500;color:#5A6B80;margin:10px 0 0;">{{ $mailOrg }}</p>
          @endif
        @endif
        {{-- Under a square mark only: a wide logo usually carries its own line. --}}
        @if($mailOwn && ! $mailWide && $mailBrand->tagline())
          <p style="font-size:12.5px;font-style:italic;color:{{ $mailColour }};margin:8px 0 0;">“{{ $mailBrand->tagline() }}”</p>
        @endif
      </td>
    </tr>

    {{-- ── The message ── --}}
    <tr>
      <td style="padding:30px 36px 8px;">
