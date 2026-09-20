<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>{{ $subject ?? 'True-Doctor' }}</title>
</head>
<body style="margin:0;padding:0;background:#F4EFE9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#F4EFE9;min-height:100vh;">
<tr><td align="center" style="padding:40px 16px 24px;">

  {{-- ── Outer card ── --}}
  <table width="600" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px;width:100%;">

    {{-- ── HEADER ── --}}
    <tr>
      <td style="background:linear-gradient(135deg,#1A0E07 0%,#3A2010 100%);border-radius:12px 12px 0 0;padding:20px 32px;">
        <div style="background:#ffffff;border-radius:8px;display:inline-block;padding:8px 16px;">
          <img src="{{ asset('images/logo-horizontal.png') }}"
               alt="True-Doctor"
               width="160"
               style="height:auto;display:block;">
        </div>
      </td>
    </tr>

    {{-- ── BODY starts ── --}}
    <tr>
      <td style="background:#FFFFFF;padding:36px 40px 8px;">
