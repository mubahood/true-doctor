@php
  $brand = \App\Support\HospitalBrand::forUser($user);
  $accent = $brand->colour() ?? '#0A6EBD';
  $org = $brand->name();
  $subject = $org ? 'Your '.$org.' staff account' : 'Your True-Doctor staff account';
@endphp
@include('emails._header')

  <p style="font-size:16px;color:#111C2E;font-weight:600;margin:0 0 8px;">Welcome, {{ $user->name }}</p>
  <p style="font-size:14px;color:#5A6B80;line-height:1.7;margin:0 0 24px;">
    An account has been made for you on {{ $org ? $org.'’s' : 'your hospital’s' }} True-Doctor system,
    where patients, appointments, visits and billing are kept. Sign in with these details:
  </p>

  <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border:1px solid #E2E8F0;margin-bottom:24px;">
    <tr>
      <td style="padding:16px 20px;border-bottom:1px solid #EEF2F7;">
        <p style="font-size:10.5px;font-weight:600;color:#93A1B3;text-transform:uppercase;letter-spacing:.12em;margin:0 0 4px;">Email</p>
        <p style="font-size:15px;color:#111C2E;margin:0;font-family:Menlo,Consolas,'Courier New',monospace;">{{ $user->email }}</p>
      </td>
    </tr>
    <tr>
      <td style="padding:16px 20px;border-bottom:1px solid #EEF2F7;">
        <p style="font-size:10.5px;font-weight:600;color:#93A1B3;text-transform:uppercase;letter-spacing:.12em;margin:0 0 4px;">Temporary password</p>
        <p style="font-size:15px;color:#111C2E;margin:0;font-family:Menlo,Consolas,'Courier New',monospace;letter-spacing:.06em;">{{ $plainPassword }}</p>
      </td>
    </tr>
    <tr>
      <td style="padding:16px 20px;">
        <p style="font-size:10.5px;font-weight:600;color:#93A1B3;text-transform:uppercase;letter-spacing:.12em;margin:0 0 4px;">Your role</p>
        <p style="font-size:14px;color:{{ $accent }};font-weight:600;margin:0;">{{ $user->role_label }}</p>
      </td>
    </tr>
  </table>

  <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom:24px;">
    <tr>
      <td align="center">
        <a href="{{ route('admin.login') }}"
           style="display:inline-block;background:{{ $accent }};color:#FFFFFF;font-size:14px;font-weight:600;
                  padding:13px 34px;text-decoration:none;border:1px solid {{ $accent }};">
          Sign in{{ $org ? ' to '.$org : '' }}
        </a>
        <p style="font-size:11.5px;color:#93A1B3;margin:12px 0 0;">{{ preg_replace('#^https?://#', '', rtrim(route('admin.login'), '/')) }}</p>
      </td>
    </tr>
  </table>

  <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-left:3px solid {{ $accent }};background:#F5F7FA;margin-bottom:8px;">
    <tr>
      <td style="padding:12px 16px;">
        <p style="font-size:12px;font-weight:600;color:#111C2E;margin:0 0 4px;">Keep it yours</p>
        <p style="font-size:12px;color:#5A6B80;line-height:1.6;margin:0;">
          You will be asked to choose your own password the first time you sign in. Never share it — every action
          in the system is recorded under your name.
        </p>
      </td>
    </tr>
  </table>

@include('emails._footer')
