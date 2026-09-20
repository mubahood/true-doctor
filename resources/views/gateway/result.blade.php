<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Payment {{ $ok ? 'received' : 'not completed' }}</title>
<style>body{font-family:Inter,system-ui,sans-serif;background:#f4f7f6;color:#1b2733;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
.card{background:#fff;border:1px solid #e3e8ec;border-radius:12px;padding:40px;max-width:420px;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.05)}
.icon{font-size:48px;margin-bottom:12px}h1{font-size:20px;margin:0 0 8px}p{color:#6b7a86;margin:0 0 20px}
a{display:inline-block;background:#0e6b63;color:#fff;text-decoration:none;padding:10px 20px;border-radius:8px}</style></head>
<body><div class="card">
  <div class="icon">{{ $ok ? '✅' : '⚠️' }}</div>
  <h1>{{ $ok ? 'Payment received' : 'Payment not completed' }}</h1>
  <p>{{ $ok ? 'Thank you. Your payment has been recorded against the invoice.' : 'The payment was not completed. If you were charged, it will reconcile automatically.' }}</p>
  <a wire:navigate href="{{ route('admin.dashboard') }}">Return to the app</a>
</div></body></html>
