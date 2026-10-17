{{-- A one-off copy's receipt (StandalonePurchaseController::receipt). Plain: black text, hairlines. --}}
@php($support = \App\Support\SupportContact::platform())
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Receipt {{ $order->reference }}</title>
<style>
  @page { margin: 14mm 12mm; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: black; line-height: 1.45; }
  h1 { font-size: 16px; margin: 0; }
  .muted { color: dimgray; }
  table { width: 100%; border-collapse: collapse; margin-top: 10px; }
  td { padding: 5px 0; border-bottom: .5px solid silver; vertical-align: top; }
  td.k { width: 40%; color: dimgray; }
  .total td { font-size: 13px; font-weight: bold; border-bottom: 1px solid black; }
  .foot { margin-top: 14px; font-size: 9px; }
</style></head>
<body>
  <h1>True-Doctor</h1>
  <div class="muted">Receipt for your own copy of True-Doctor
    @if($support->email()) · {{ $support->email() }} @endif</div>
  <table>
    <tr><td class="k">Receipt reference</td><td><b>{{ $order->reference }}</b></td></tr>
    <tr><td class="k">Paid on</td><td>{{ $order->paid_at?->format('j F Y, H:i') }}</td></tr>
    <tr><td class="k">Bought by</td><td>{{ $order->customer_name }}<br>{{ $order->customer_email }}</td></tr>
    <tr><td class="k">For</td><td>{{ $order->kind === 'renewal' ? 'Renewal of updates' : ($product?->name ?? 'True-Doctor, your own copy') }}</td></tr>
    <tr><td class="k">Licensed address</td><td>{{ $order->domain }}</td></tr>
    <tr><td class="k">Updates included for</td><td>{{ $order->updates_months }} months, until {{ $licence->updates_until->format('j F Y') }}</td></tr>
    <tr><td class="k">Licence</td><td>{{ $licence->id }}</td></tr>
    @if($order->gateway_tracking_id)<tr><td class="k">Payment tracking number</td><td>{{ $order->gateway_tracking_id }}</td></tr>@endif
    <tr class="total"><td class="k">Amount paid</td><td>UGX {{ number_format((float) $order->amount_ugx) }}</td></tr>
  </table>
  <p class="foot">Keep this receipt. Your downloads, licence file, user manual and receipts stay on your downloads page at {{ route('standalone.portal') }}. If you lose the link, enter your email there and a new one is sent to you.</p>
</body></html>
