@php($R = \App\Services\Reports\MonthlyReports::class)
{{-- A monthly report on the letterhead (docs/reports.md, Monthly reports): the
     detailed table on its side, or the summary upright. Plain: the figures,
     their headings and the period, nothing else. --}}
<x-pdf.document
  :title="$report->title()"
  :reference="$period->label()"
  :meta="[
      'View' => $R::VIEWS[$view],
      'Period' => \Illuminate\Support\Carbon::parse($period->from)->format('j M Y').' to '.\Illuminate\Support\Carbon::parse($period->to)->format('j M Y'),
      'Prepared by' => $generatedBy,
  ]">

  @if($view === 'detail')
    @php($columns = $report->columns())
    <table class="rows small">
      <thead>
        <tr>
          @foreach($columns as $c)
            <th @class(['r' => in_array($c['type'], ['money', 'int'], true)])>{{ $c['label'] }}</th>
          @endforeach
        </tr>
      </thead>
      <tbody>
        @forelse($rows as $row)
          <tr>
            @foreach($columns as $c)
              <td @class(['r num' => in_array($c['type'], ['money', 'int'], true)])>{{ $R::display($row[$c['key']] ?? null, $c['type']) }}</td>
            @endforeach
          </tr>
        @empty
          <tr><td colspan="{{ count($columns) }}" class="muted">Nothing in this period.</td></tr>
        @endforelse
      </tbody>
    </table>

    @if($count > count($rows))
      <p class="muted small foot-note">This PDF prints the first {{ number_format(count($rows)) }} of {{ number_format($count) }} lines. The other {{ number_format($count - count($rows)) }} are in the Excel and CSV files, which have every line. The totals below cover them all.</p>
    @endif

    <h2>Totals</h2>
    <table class="sums">
      @foreach($totals as $t)
        <tr @class(['grand' => $loop->last])><td>{{ $t['label'] }}</td><td class="r">{{ $R::display($t['value'], $t['type']) }}</td></tr>
      @endforeach
    </table>
  @else
    <table class="rows">
      <tbody>
        @foreach($summary['figures'] as $f)
          <tr>
            <td class="muted">{{ $f['label'] }}</td>
            <td class="r num"><b>{{ $R::display($f['value'], $f['type']) }}</b></td>
            <td class="muted small">{{ $f['note'] ?? '' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>

    @foreach($summary['tables'] as $table)
      <h2>{{ $table['title'] }}</h2>
      <table class="rows">
        <thead>
          <tr>
            @foreach($table['columns'] as $c)
              <th @class(['r' => in_array($c['type'], ['money', 'int', 'percent'], true)])>{{ $c['label'] }}</th>
            @endforeach
          </tr>
        </thead>
        <tbody>
          @forelse($table['rows'] as $row)
            <tr>
              @foreach($table['columns'] as $c)
                <td @class(['r num' => in_array($c['type'], ['money', 'int', 'percent'], true)])>{{ $R::display($row[$c['key']] ?? null, $c['type']) }}</td>
              @endforeach
            </tr>
          @empty
            <tr><td colspan="{{ count($table['columns']) }}" class="muted">Nothing in this period.</td></tr>
          @endforelse
          @if(! empty($table['total']) && $table['rows'] !== [])
            <tr>
              @foreach($table['columns'] as $c)
                <td @class(['r num' => in_array($c['type'], ['money', 'int', 'percent'], true)])><b>{{ $R::display($table['total'][$c['key']] ?? null, $c['type'] === 'percent' ? 'text' : $c['type']) }}</b></td>
              @endforeach
            </tr>
          @endif
        </tbody>
      </table>
      @if(! empty($table['note']))<p class="muted small">{{ $table['note'] }}</p>@endif
    @endforeach
  @endif
</x-pdf.document>
