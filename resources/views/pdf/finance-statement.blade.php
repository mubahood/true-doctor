@php($set = app(\App\Support\HospitalSettings::class))
{{-- A financial statement as a document (App\Http\Controllers\Admin\FinanceExportController).
     The same figures the screen shows, with the period and the moment it was
     taken printed on it, for a bank, an auditor or the owners. --}}
<x-pdf.document
  :title="$title"
  :reference="$period"
  :meta="array_filter([
      'Compared with' => $compare,
      'Generated' => $generatedAt->format('d M Y H:i'),
      'By' => $generatedBy,
  ])">

  @switch($report)
    @case('income')
      @php($cmp = $d['compare'] !== null)
      @foreach([
          ['Income from patient care', $d['revenue'], 'net_revenue', 'Net income from patient care'],
          ['Discounts given', $d['discounts'], null, null],
          ['Cost of drugs and supplies sold', $d['cost_of_sales'], 'gross_profit', 'Gross profit'],
          ['Running costs', $d['expenses'], 'operating_profit', 'Operating profit'],
          ['Other income', $d['other_income'], 'net_profit', (float) $d['totals']['net_profit'] < 0 ? 'Net loss' : 'Net profit'],
        ] as [$heading, $section, $after, $afterLabel])
        @if($section['rows'] !== [])
          <h2>{{ $heading }}</h2>
          <table class="rows">
            <thead><tr><th style="width:{{ $cmp ? 52 : 70 }}%;">Account</th><th class="r">This period</th>@if($cmp)<th class="r">Compared</th>@endif</tr></thead>
            <tbody>
              @foreach($section['rows'] as $r)
                <tr><td>{{ $r['account']->code }} · {{ $r['account']->name }}</td><td class="r num">{{ $set->format($r['amount']) }}</td>@if($cmp)<td class="r num muted">{{ $set->format($r['compare'] ?? '0') }}</td>@endif</tr>
              @endforeach
            </tbody>
          </table>
        @endif
        @if($after)
          <table class="sums"><tr class="{{ $after === 'net_profit' ? 'grand' : '' }}"><td>{{ $afterLabel }}</td><td class="r">{{ $set->format($d['totals'][$after]) }}@if($cmp) <span class="muted">/ {{ $set->format($d['compare'][$after]) }}</span>@endif</td></tr></table>
        @endif
      @endforeach
      @break

    @case('balance')
      @foreach(['Current assets' => $d['current_assets'], 'Equipment and other fixed assets' => $d['fixed_assets'], 'Owed now' => $d['current_liabilities'], 'Long-term loans' => $d['long_term_liabilities'], 'Equity' => $d['equity']] as $heading => $section)
        @if($section['rows'] !== [] || $heading === 'Equity')
          <h2>{{ $heading }}</h2>
          <table class="rows">
            <tbody>
              @foreach($section['rows'] as $r)
                <tr><td style="width:70%;">{{ $r['account']->code }} · {{ $r['account']->name }}</td><td class="r num">{{ $set->format($r['amount']) }}</td></tr>
              @endforeach
              @if($heading === 'Equity')
                <tr><td>Profit kept from earlier years</td><td class="r num">{{ $set->format($d['earlier_profit']) }}</td></tr>
                <tr><td>Profit this year</td><td class="r num">{{ $set->format($d['year_profit']) }}</td></tr>
              @endif
            </tbody>
          </table>
        @endif
      @endforeach
      <table class="sums">
        <tr><td>Total assets</td><td class="r">{{ $set->format($d['assets']) }}</td></tr>
        <tr><td>Total liabilities</td><td class="r">{{ $set->format($d['liabilities']) }}</td></tr>
        <tr><td>Total equity</td><td class="r">{{ $set->format($d['equity_total']) }}</td></tr>
        <tr class="grand"><td>Liabilities and equity</td><td class="r">{{ $set->format($d['liabilities_and_equity']) }}</td></tr>
      </table>
      @break

    @case('trial')
      <table class="rows">
        <thead><tr><th style="width:12%;">Code</th><th>Account</th><th class="r">Debit</th><th class="r">Credit</th></tr></thead>
        <tbody>
          @foreach($d['rows'] as $r)
            <tr><td class="num">{{ $r['account']->code }}</td><td>{{ $r['account']->name }}</td><td class="r num">{{ (float) $r['debit'] > 0 ? $set->format($r['debit']) : '' }}</td><td class="r num">{{ (float) $r['credit'] > 0 ? $set->format($r['credit']) : '' }}</td></tr>
          @endforeach
        </tbody>
      </table>
      <table class="sums">
        <tr><td>Debits</td><td class="r">{{ $set->format($d['debit']) }}</td></tr>
        <tr class="grand"><td>Credits</td><td class="r">{{ $set->format($d['credit']) }}</td></tr>
      </table>
      <p class="muted small">Income and expenses from {{ \Illuminate\Support\Carbon::parse($d['year_start'])->format('d M Y') }}; earlier years' profit is in retained earnings.</p>
      @break

    @case('cashflow')
      <h2>Money in</h2>
      <table class="rows"><tbody>
        @forelse($d['inflows'] as $r)<tr><td style="width:70%;">{{ $r['label'] }}</td><td class="r num">{{ $set->format($r['amount']) }}</td></tr>@empty<tr><td class="muted">None.</td><td></td></tr>@endforelse
      </tbody></table>
      <h2>Money out</h2>
      <table class="rows"><tbody>
        @forelse($d['outflows'] as $r)<tr><td style="width:70%;">{{ $r['label'] }}</td><td class="r num">{{ $set->format($r['amount']) }}</td></tr>@empty<tr><td class="muted">None.</td><td></td></tr>@endforelse
      </tbody></table>
      <h2>By book</h2>
      <table class="rows">
        <thead><tr><th>Book</th><th class="r">Start</th><th class="r">In</th><th class="r">Out</th><th class="r">End</th></tr></thead>
        <tbody>
          @foreach($d['books'] as $b)
            <tr><td>{{ $b['account']->name }}</td><td class="r num">{{ $set->format($b['opening']) }}</td><td class="r num">{{ $set->format($b['in']) }}</td><td class="r num">{{ $set->format($b['out']) }}</td><td class="r num">{{ $set->format($b['closing']) }}</td></tr>
          @endforeach
        </tbody>
      </table>
      <table class="sums">
        <tr><td>Cash at the start</td><td class="r">{{ $set->format($d['opening']) }}</td></tr>
        <tr><td>Net change</td><td class="r">{{ $set->format(bcsub($d['in'], $d['out'], 2)) }}</td></tr>
        <tr class="grand"><td>Cash at the end</td><td class="r">{{ $set->format($d['closing']) }}</td></tr>
      </table>
      @break
  @endswitch
</x-pdf.document>
