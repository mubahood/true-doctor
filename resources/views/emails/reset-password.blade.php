@php
  $brand = \App\Support\HospitalBrand::forUser($user);
  $accent = $brand->colour() ?? '#0A6EBD';
  $org = $brand->name();
  $subject = 'Reset your password'.($org ? ' — '.$org : '');
@endphp
@include('emails._header')

  <p style="font-size:16px;color:#111C2E;font-weight:600;margin:0 0 8px;">Hello {{ $user->name }},</p>
  <p style="font-size:14px;color:#5A6B80;line-height:1.7;margin:0 0 24px;">
    Somebody — hopefully you — asked to reset the password for your {{ $org ? $org.' ' : '' }}staff account
    (<span style="color:#111C2E;">{{ $user->email }}</span>). Choose a new one with the button below.
  </p>

  <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom:24px;">
    <tr>
      <td align="center" style="padding:6px 0 4px;">
        <a href="{{ $resetUrl }}"
           style="display:inline-block;background:{{ $accent }};color:#FFFFFF;font-size:14px;font-weight:600;
                  padding:13px 34px;text-decoration:none;border:1px solid {{ $accent }};">
          Choose a new password
        </a>
        <p style="font-size:11.5px;color:#93A1B3;margin:12px 0 0;">The link works for {{ $expiresIn }} and only once.</p>
      </td>
    </tr>
  </table>

  <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#F5F7FA;margin-bottom:20px;">
    <tr>
      <td style="padding:12px 16px;">
        <p style="font-size:11px;color:#5A6B80;margin:0 0 6px;">If the button does not open, copy this address into your browser:</p>
        <p style="font-size:10.5px;color:{{ $accent }};font-family:Menlo,Consolas,'Courier New',monospace;word-break:break-all;margin:0;">{{ $resetUrl }}</p>
      </td>
    </tr>
  </table>

  <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-left:3px solid #B45309;background:#FBF0E2;margin-bottom:8px;">
    <tr>
      <td style="padding:12px 16px;">
        <p style="font-size:12px;font-weight:600;color:#92400E;margin:0 0 4px;">Didn’t ask for this?</p>
        <p style="font-size:12px;color:#7C5A2A;line-height:1.6;margin:0;">
          Ignore this email — your password stays as it is. If it keeps happening, tell your administrator.
        </p>
      </td>
    </tr>
  </table>

@include('emails._footer')
