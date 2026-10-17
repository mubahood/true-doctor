@php($set = app(\App\Support\HospitalSettings::class))
@php($invoice = $payment->invoice)
<x-pdf.thermal :width="$width" :title="'Receipt '.$invoice?->invoice_no">
  <div class="c title">RECEIPT</div>
  <table class="small">
    <tr><td>{{ $invoice?->invoice_no }}</td><td class="r">{{ $payment->created_at?->format('d M Y H:i') }}</td></tr>
    <tr><td colspan="2">{{ $invoice?->patient?->full_name }} <span class="num">{{ $invoice?->patient?->patient_no }}</span></td></tr>
  </table>
  <div class="rule"></div>
  <table>
    @foreach($invoice?->items ?? [] as $item)
      <tr>
        <td>{{ $item->description }}@if((float) $item->quantity !== 1.0) <span class="small">× {{ rtrim(rtrim((string) $item->quantity, '0'), '.') }}</span>@endif</td>
        <td class="r num">{{ $set->format($item->line_total) }}</td>
      </tr>
    @endforeach
    <tr class="total"><td>Total</td><td class="r num">{{ $set->format($invoice?->total ?? '0') }}</td></tr>
  </table>
  <div class="rule"></div>
  <table>
    <tr><td>Paid ({{ $payment->method->label() }})</td><td class="r num">{{ $set->format($payment->amount) }}</td></tr>
    @if($payment->reference)<tr><td colspan="2" class="small">Ref {{ $payment->reference }}</td></tr>@endif
    <tr class="total"><td>{{ bccomp((string) $payment->balance_after, '0', 2) === 0 ? 'Paid in full' : 'Balance' }}</td>
      <td class="r num">{{ bccomp((string) $payment->balance_after, '0', 2) === 0 ? '' : $set->format($payment->balance_after) }}</td></tr>
  </table>
  <div class="rule"></div>
  <div class="c small">Served by {{ $payment->receivedBy?->name ?? '-' }}</div>
  <div class="c small">Thank you. Keep this receipt.</div>
</x-pdf.thermal>
