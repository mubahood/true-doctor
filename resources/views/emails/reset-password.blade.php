@php $subject = 'Reset Your True-Doctor Password'; @endphp
@include('emails._header')

  <p style="font-size:15px;color:#1A0F07;font-weight:700;margin:0 0 6px;">
    Hello, {{ $user->name }},
  </p>
  <p style="font-size:14px;color:#5A4A3A;line-height:1.7;margin:0 0 24px;">
    We received a request to reset the password for your True-Doctor account.
    Click the button below to set a new password.
  </p>

  {{-- Reset Button --}}
  <table width="100%" cellpadding="0" cellspacing="0" role="presentation"
         style="background:#FDF9F5;border:1.5px solid #E8DDD4;border-radius:8px;margin-bottom:24px;">
    <tr>
      <td style="padding:28px 24px;text-align:center;">
        <a href="{{ $resetUrl }}"
           style="display:inline-block;background:#5D3A1A;color:#ffffff;font-size:14px;font-weight:700;
                  letter-spacing:0.04em;padding:14px 40px;border-radius:7px;text-decoration:none;">
          Reset My Password
        </a>
        <p style="font-size:11px;color:#9A8A7A;margin:16px 0 0;line-height:1.6;">
          This link will expire in <strong>{{ $expiresIn }}</strong>.
        </p>
      </td>
    </tr>
  </table>

  {{-- Fallback link --}}
  <table width="100%" cellpadding="0" cellspacing="0" role="presentation"
         style="background:#F7F3EE;border-radius:6px;margin-bottom:20px;">
    <tr>
      <td style="padding:14px 16px;">
        <p style="font-size:11px;color:#7A6555;margin:0 0 6px;">If the button above doesn't work, copy and paste this URL into your browser:</p>
        <p style="font-size:10.5px;color:#5D3A1A;font-family:'Courier New',monospace;word-break:break-all;margin:0;">{{ $resetUrl }}</p>
      </td>
    </tr>
  </table>

  {{-- Security note --}}
  <table width="100%" cellpadding="0" cellspacing="0" role="presentation"
         style="background:#FFF8F0;border:1.5px solid #F0D8B8;border-radius:7px;">
    <tr>
      <td style="padding:14px 18px;">
        <p style="font-size:12px;font-weight:700;color:#8B4513;margin:0 0 5px;">&#9888; Didn't request this?</p>
        <p style="font-size:12px;color:#7A5A3A;line-height:1.7;margin:0;">
          If you did not request a password reset, no action is required — your account is safe.
          If you are concerned, please contact your administrator immediately.
        </p>
      </td>
    </tr>
  </table>

@include('emails._footer')
