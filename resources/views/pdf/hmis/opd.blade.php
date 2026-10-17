<x-pdf.document title="HMIS 105: Outpatient monthly report" :reference="$report['period']['from'].' to '.$report['period']['to']" :meta="['Visits' => (string) $report['total_visits'], 'Not counted' => (string) count($report['missing']), 'Form' => $version]">
  @php($bands = $report['bands'])
  <table class="rows">
    <thead>
      <tr><th>Line</th>@foreach($bands as $label)<th class="r">{{ $label }} M</th><th class="r">F</th>@endforeach<th class="r">Total</th></tr>
    </thead>
    <tbody>
      @foreach(['New attendance' => $report['attendance']['new'], 'Re-attendance' => $report['attendance']['re']] as $label => $grid)
        <tr><td><b>{{ $label }}</b></td>@foreach(array_keys($bands) as $b)<td class="num r">{{ $grid[$b]['male'] }}</td><td class="num r">{{ $grid[$b]['female'] }}</td>@endforeach<td class="num r">{{ collect($grid)->sum(fn ($s) => $s['male'] + $s['female']) }}</td></tr>
      @endforeach
      @foreach($report['diagnoses'] as $d)
        <tr><td>{{ $d['label'] }}</td>@foreach(array_keys($bands) as $b)<td class="num r">{{ $d['counts'][$b]['male'] }}</td><td class="num r">{{ $d['counts'][$b]['female'] }}</td>@endforeach<td class="num r">{{ $d['total'] }}</td></tr>
      @endforeach
      <tr><td>All other diagnoses</td><td colspan="{{ count($bands) * 2 }}"></td><td class="num r">{{ $report['other'] }}</td></tr>
    </tbody>
  </table>
  <p class="foot-note muted small">Counted by the primary coded diagnosis of each visit. {{ count($report['missing']) }} visit(s) could not be counted (no age, no sex or no coded diagnosis); they are listed in Reports, HMIS.</p>
</x-pdf.document>
