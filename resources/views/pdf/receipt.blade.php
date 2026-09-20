@php($set = app(\App\Support\HospitalSettings::class))
@php($invoice = $payment->invoice)
<x-pdf.document
  title="Receipt"
  :reference="$invoice?->invoice_no"
  :meta="[
      'Received' => $payment->created_at?->format('d M Y H:i'),
      'Method' => $payment->method->label(),
  ]">

  {{-- A receipt is read for one figure, so it is the first thing on it. --}}
  <table class="party">
    <tr>
      <td style="width:62%;">
        <span class="k">Received from</span>
        <span class="v big">{{ $invoice?->patient?->full_name ?? '—' }}</span>
        <span class="v muted num small">{{ $invoice?->patient?->patient_no }}</span>
      </td>
      <td style="width:38%;" class="r">
        <span class="k">Amount received</span>
        <span class="headline">{{ $set->format($payment->amount) }}</span>
      </td>
    </tr>
  </table>

  <h2>Against</h2>

  <table class="rows">
    <tbody>
      <tr>
        <td style="width:34%;" class="muted">Invoice</td>
        <td class="num">{{ $invoice?->invoice_no ?? '—' }}</td>
      </tr>
      @if($invoice?->visit)
        <tr><td class="muted">Visit</td><td class="num">{{ $invoice->visit->visit_no }}</td></tr>
      @endif
      <tr><td class="muted">Invoice total</td><td class="num">{{ $set->format($invoice?->total ?? '0') }}</td></tr>
      <tr><td class="muted">Paid to date</td><td class="num">{{ $set->format($invoice?->amount_paid ?? '0') }}</td></tr>
      <tr>
        <td class="muted">Balance after this payment</td>
        <td class="num">
          {{ $set->format($payment->balance_after) }}
          @if(bccomp((string) $payment->balance_after, '0', 2) === 0)
            <span class="stamp ok">Settled</span>
          @endif
        </td>
      </tr>
    </tbody>
  </table>

  <h2>How it was paid</h2>

  <table class="rows">
    <tbody>
      <tr><td style="width:34%;" class="muted">Method</td><td>{{ $payment->method->label() }}</td></tr>
      @if($payment->reference)
        <tr><td class="muted">Reference</td><td class="num">{{ $payment->reference }}</td></tr>
      @endif
      @if($payment->card)
        <tr><td class="muted">Card</td><td class="num">{{ $payment->card->masked() }}</td></tr>
      @endif
      <tr><td class="muted">Received by</td><td>{{ $payment->receivedBy?->name ?? '—' }}</td></tr>
    </tbody>
  </table>

  <p class="foot-note muted small">
    This receipt confirms the payment above. Keep it — it is the proof of what
    was paid, when, and against which invoice.
  </p>
</x-pdf.document>
