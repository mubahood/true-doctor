@php($set = app(\App\Support\HospitalSettings::class))
<x-pdf.document
  title="Invoice"
  :reference="$invoice->invoice_no"
  :meta="[
      'Issued' => $invoice->issued_at?->format('d M Y'),
      'Status' => $invoice->status->label(),
  ]">

  {{-- Who it is for, and what it is for. Three columns rather than a
       paragraph: whoever is reading it is looking for one of the three. --}}
  <table class="party">
    <tr>
      <td>
        <span class="k">Billed to</span>
        <span class="v big">{{ $invoice->patient?->full_name ?? '—' }}</span>
        <span class="v muted num small">{{ $invoice->patient?->patient_no }}</span>
      </td>
      <td>
        <span class="k">Visit</span>
        <span class="v num">{{ $invoice->visit?->visit_no ?? '—' }}</span>
      </td>
      <td class="r">
        <span class="k">Amount due</span>
        <span class="v big num">{{ $set->format($invoice->balance) }}</span>
      </td>
    </tr>
  </table>

  <h2>What was charged</h2>

  <table class="rows">
    <thead>
      <tr>
        <th style="width:52%;">Description</th>
        <th class="r" style="width:10%;">Qty</th>
        <th class="r" style="width:19%;">Unit price</th>
        <th class="r" style="width:19%;">Amount</th>
      </tr>
    </thead>
    <tbody>
      @forelse($invoice->items as $item)
        <tr>
          <td>
            {{ $item->description }}
            @if($item->tax_exempt)<span class="muted small">· exempt</span>@endif
          </td>
          <td class="r num">{{ rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.') }}</td>
          <td class="r num">{{ $set->format($item->unit_price) }}</td>
          <td class="r num">{{ $set->format($item->line_total) }}</td>
        </tr>
      @empty
        <tr><td colspan="4" class="muted">Nothing was charged on this invoice.</td></tr>
      @endforelse
    </tbody>
  </table>

  <table class="sums">
    <tr><td>Subtotal</td><td class="r">{{ $set->format($invoice->subtotal) }}</td></tr>

    @if(bccomp((string) $invoice->discount, '0', 2) > 0)
      <tr><td>Discount</td><td class="r">− {{ $set->format($invoice->discount) }}</td></tr>
    @endif

    @if(bccomp((string) $invoice->tax_total, '0', 2) > 0)
      <tr><td>{{ $set->taxLabel() }}</td><td class="r">{{ $set->format($invoice->tax_total) }}</td></tr>
    @endif

    <tr class="grand"><td>Total</td><td class="r">{{ $set->format($invoice->total) }}</td></tr>

    @if(bccomp((string) $invoice->amount_paid, '0', 2) > 0)
      <tr><td>Paid</td><td class="r">− {{ $set->format($invoice->amount_paid) }}</td></tr>
      <tr><td><b>Balance due</b></td><td class="r"><b>{{ $set->format($invoice->balance) }}</b></td></tr>
    @endif
  </table>

  @if(bccomp((string) $invoice->balance, '0', 2) === 0)
    <p class="foot-note"><span class="stamp ok">Paid in full</span></p>
  @endif
</x-pdf.document>
