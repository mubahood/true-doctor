@php $subject = 'Welcome to True-Doctor — Your Account'; @endphp
@include('emails._header')

  {{-- Greeting --}}
  <p style="font-size:15px;color:#1A0F07;font-weight:700;margin:0 0 6px;">Hello, {{ $user->name }},</p>
  <p style="font-size:14px;color:#5A4A3A;line-height:1.7;margin:0 0 24px;">
    Your account on the <strong>True-Doctor</strong> trading simulator platform has been created.
    Use the credentials below to sign in for the first time.
  </p>

  {{-- Credentials box --}}
  <table width="100%" cellpadding="0" cellspacing="0" role="presentation"
         style="background:#FDF9F5;border:1.5px solid #E8DDD4;border-radius:8px;margin-bottom:24px;">
    <tr>
      <td style="padding:24px 28px;">
        <p style="font-size:10px;font-weight:800;color:#C4956A;letter-spacing:0.18em;text-transform:uppercase;margin:0 0 16px;">
          Your Login Credentials
        </p>

        <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
          <tr>
            <td style="padding-bottom:12px;">
              <p style="font-size:11px;font-weight:700;color:#9A8A7A;text-transform:uppercase;letter-spacing:0.1em;margin:0 0 3px;">Email Address</p>
              <p style="font-size:15px;font-weight:600;color:#1A0F07;margin:0;font-family:'Courier New',monospace;">{{ $user->email }}</p>
            </td>
          </tr>
          <tr>
            <td style="padding-bottom:12px;border-top:1px solid #E8DDD4;padding-top:12px;">
              <p style="font-size:11px;font-weight:700;color:#9A8A7A;text-transform:uppercase;letter-spacing:0.1em;margin:0 0 3px;">Temporary Password</p>
              <p style="font-size:15px;font-weight:600;color:#1A0F07;margin:0;font-family:'Courier New',monospace;letter-spacing:0.08em;">{{ $plainPassword }}</p>
            </td>
          </tr>
          <tr>
            <td style="border-top:1px solid #E8DDD4;padding-top:12px;">
              <p style="font-size:11px;font-weight:700;color:#9A8A7A;text-transform:uppercase;letter-spacing:0.1em;margin:0 0 3px;">Role</p>
              <p style="font-size:14px;font-weight:600;color:#5D3A1A;margin:0;">{{ $user->role_label }}</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  {{-- CTA Button --}}
  <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom:24px;">
    <tr>
      <td align="center">
        <a href="{{ route('admin.login') }}"
           style="display:inline-block;background:#5D3A1A;color:#ffffff;font-size:14px;font-weight:700;
                  letter-spacing:0.04em;padding:13px 36px;border-radius:7px;text-decoration:none;">
          Sign In to True-Doctor
        </a>
      </td>
    </tr>
  </table>

  {{-- Security warning --}}
  <table width="100%" cellpadding="0" cellspacing="0" role="presentation"
         style="background:#FFF8F0;border:1.5px solid #F0D8B8;border-radius:7px;margin-bottom:8px;">
    <tr>
      <td style="padding:16px 20px;">
        <p style="font-size:12px;font-weight:700;color:#8B4513;margin:0 0 6px;">&#9888; Important Security Notice</p>
        <ul style="font-size:12px;color:#7A5A3A;line-height:1.8;margin:0;padding-left:18px;">
          <li>Please <strong>change your password immediately</strong> after your first login.</li>
          <li>Never share your login credentials with anyone.</li>
          <li>If you did not expect this email, contact your administrator immediately.</li>
        </ul>
      </td>
    </tr>
  </table>

@include('emails._footer')
