@php($set = app(\App\Support\HospitalSettings::class))
@php($invoice = $credit->invoice)
@php($isRefund = $credit->kind === \App\Enums\CreditKind::Refund)
<x-pdf.document
  :title="$credit->kind->label()"
  :reference="$credit->number"
  :meta="[
      'Date' => $credit->decided_at?->format('d M Y H:i'),
      'Invoice' => $invoice?->invoice_no,
  ]">

  <table class="party">
    <tr>
      <td style="width:62%;">
        <span class="k">{{ $isRefund ? 'Paid to' : 'Issued to' }}</span>
        <span class="v big">{{ $invoice?->patient?->full_name ?? '—' }}</span>
        <span class="v muted num small">{{ $invoice?->patient?->patient_no }}</span>
      </td>
      <td style="width:38%;" class="r">
        <span class="k">{{ $isRefund ? 'Amount refunded' : 'Amount credited' }}</span>
        <span class="headline">{{ $set->format($credit->amount) }}</span>
      </td>
    </tr>
  </table>

  <h2>Reason</h2>
  <p>{{ $credit->reason }}</p>

  <h2>Against</h2>
  <table class="rows">
    <tbody>
      <tr><td style="width:34%;" class="muted">Invoice</td><td class="num">{{ $invoice?->invoice_no ?? '—' }}</td></tr>
      @if($invoice?->visit)
        <tr><td class="muted">Visit</td><td class="num">{{ $invoice->visit->visit_no }}</td></tr>
      @endif
      <tr><td class="muted">Invoice total</td><td class="num">{{ $set->format($invoice?->total ?? '0') }}</td></tr>
      @if(! $isRefund && bccomp((string) $credit->tax_part, '0', 2) > 0)
        <tr><td class="muted">Of which tax</td><td class="num">{{ $set->format($credit->tax_part) }}</td></tr>
      @endif
      <tr><td class="muted">Credited to date</td><td class="num">{{ $set->format($invoice?->credited ?? '0') }}</td></tr>
      <tr><td class="muted">Paid to date</td><td class="num">{{ $set->format($invoice?->amount_paid ?? '0') }}</td></tr>
      <tr><td class="muted">Refunded to date</td><td class="num">{{ $set->format($invoice?->refunded ?? '0') }}</td></tr>
      <tr><td class="muted">Balance now</td><td class="num">{{ $set->format($invoice?->balance ?? '0') }}</td></tr>
    </tbody>
  </table>

  @if($isRefund)
    <h2>How it was paid back</h2>
    <table class="rows">
      <tbody>
        <tr><td style="width:34%;" class="muted">Method</td><td>{{ $credit->method?->label() ?? '—' }}</td></tr>
        @if($credit->reference)
          <tr><td class="muted">Reference</td><td class="num">{{ $credit->reference }}</td></tr>
        @endif
        @if($credit->card)
          <tr><td class="muted">Card</td><td class="num">{{ $credit->card->masked() }}</td></tr>
        @endif
      </tbody>
    </table>
  @endif

  <h2>Approval</h2>
  <table class="rows">
    <tbody>
      <tr><td style="width:34%;" class="muted">Asked for by</td><td>{{ $credit->requestedBy?->name ?? '—' }}, {{ $credit->requested_at->format('d M Y H:i') }}</td></tr>
      <tr><td class="muted">Approved by</td><td>{{ $credit->decidedBy?->name ?? '—' }}, {{ $credit->decided_at?->format('d M Y H:i') }}</td></tr>
    </tbody>
  </table>

  @if($isRefund)
    <p class="foot-note muted small">Received by the patient: ______________________ &nbsp; Date: __________</p>
  @else
    <p class="foot-note muted small">
      This credit note reduces what invoice {{ $invoice?->invoice_no }} asks for by the amount above.
    </p>
  @endif
</x-pdf.document>
