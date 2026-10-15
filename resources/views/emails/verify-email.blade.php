@php
  $brand = \App\Support\HospitalBrand::forUser($user);
  $accent = $brand->colour() ?? '#0A6EBD';
  $org = $brand->name();
  $subject = 'Confirm your email address'.($org ? ' — '.$org : '');
@endphp
@include('emails._header')

  <p style="font-size:16px;color:#111C2E;font-weight:600;margin:0 0 8px;">Hello {{ $user->name }},</p>
  <p style="font-size:14px;color:#5A6B80;line-height:1.7;margin:0 0 24px;">
    Please confirm that <span style="color:#111C2E;">{{ $user->email }}</span> is your address. It is how
    {{ $org ? $org.'’s' : 'your hospital’s' }} True-Doctor system reaches you — password resets included — and it
    opens offline working (Field Mode) on your devices.
  </p>

  <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom:24px;">
    <tr>
      <td align="center" style="padding:6px 0 4px;">
        <a href="{{ $verifyUrl }}"
           style="display:inline-block;background:{{ $accent }};color:#FFFFFF;font-size:14px;font-weight:600;
                  padding:13px 34px;text-decoration:none;border:1px solid {{ $accent }};">
          Confirm my address
        </a>
        <p style="font-size:11.5px;color:#93A1B3;margin:12px 0 0;">The link works for {{ $expiresIn }}.</p>
      </td>
    </tr>
  </table>

  <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#F5F7FA;margin-bottom:8px;">
    <tr>
      <td style="padding:12px 16px;">
        <p style="font-size:11px;color:#5A6B80;margin:0 0 6px;">If the button does not open, copy this address into your browser:</p>
        <p style="font-size:10.5px;color:{{ $accent }};font-family:Menlo,Consolas,'Courier New',monospace;word-break:break-all;margin:0;">{{ $verifyUrl }}</p>
      </td>
    </tr>
  </table>

@include('emails._footer')
