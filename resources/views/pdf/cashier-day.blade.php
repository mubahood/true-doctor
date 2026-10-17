@php($set = app(\App\Support\HospitalSettings::class))
<x-pdf.document
  title="Cashier day summary"
  :reference="$summary['date']"
  :meta="['Payments' => (string) $summary['payments_count'], 'Invoices raised' => (string) $summary['invoices_raised'], 'Voided' => (string) $summary['voided']]">

  <table class="party">
    <tr>
      <td style="width:62%;">
        <span class="k">Day</span>
        <span class="v big">{{ \Illuminate\Support\Carbon::parse($summary['date'])->format('d M Y') }}</span>
      </td>
      <td style="width:38%;" class="r">
        <span class="k">Total received</span>
        <span class="headline">{{ $set->format($summary['total']) }}</span>
      </td>
    </tr>
  </table>

  <h2>By method</h2>
  <table class="rows">
    <thead><tr><th>Method</th><th class="r">Payments</th><th class="r">Amount</th></tr></thead>
    <tbody>
      @forelse($summary['totals_by_method'] as $row)
        <tr>
          <td>{{ \App\Enums\PaymentMethod::tryFrom($row['method'])?->label() ?? $row['method'] }}</td>
          <td class="num r">{{ $row['count'] }}</td>
          <td class="num r">{{ $set->format($row['amount']) }}</td>
        </tr>
      @empty
        <tr><td colspan="3" class="muted">No payments were taken.</td></tr>
      @endforelse
    </tbody>
  </table>

  {{-- Refunds and credit notes (docs/refunds.md). --}}
  <h2>Given back</h2>
  <table class="rows">
    <tbody>
      @foreach($summary['refunds']['by_method'] as $r)
        <tr><td>Refunded · {{ \App\Enums\PaymentMethod::tryFrom($r['method'])?->label() ?? $r['method'] }}</td><td class="num r">− {{ $set->format($r['amount']) }}</td></tr>
      @endforeach
      <tr><td>Refunds</td><td class="num r">{{ $summary['refunds']['count'] }} · {{ $set->format($summary['refunds']['total']) }}</td></tr>
      <tr><td>Credit notes (no money moved)</td><td class="num r">{{ $summary['credit_notes']['count'] }} · {{ $set->format($summary['credit_notes']['total']) }}</td></tr>
      <tr><td><b>Net received</b></td><td class="num r"><b>{{ $set->format($summary['net']) }}</b></td></tr>
    </tbody>
  </table>

  <h2>By person</h2>
  <table class="rows">
    <thead><tr><th>Received by</th><th class="r">Total</th></tr></thead>
    <tbody>
      @forelse($summary['by_user'] as $row)
        <tr><td>{{ $row['name'] }}</td><td class="num r">{{ $set->format($row['total']) }}</td></tr>
      @empty
        <tr><td colspan="2" class="muted">Nobody.</td></tr>
      @endforelse
    </tbody>
  </table>
</x-pdf.document>
