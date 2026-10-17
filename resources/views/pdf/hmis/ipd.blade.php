<x-pdf.document title="HMIS 108: Inpatient monthly report" :reference="$report['period']['from'].' to '.$report['period']['to']" :meta="['Bed days' => (string) $report['bed_days'], 'Absconded' => (string) $report['absconded'], 'Form' => $version]">
  @php($bands = $report['bands'])
  <table class="rows">
    <thead><tr><th>Line</th>@foreach($bands as $label)<th class="r">{{ $label }} M</th><th class="r">F</th>@endforeach<th class="r">Total</th></tr></thead>
    <tbody>
      @foreach(['Admissions' => $report['admissions'], 'Discharges' => $report['discharges'], 'Deaths' => $report['deaths']] as $label => $grid)
        <tr><td><b>{{ $label }}</b></td>@foreach(array_keys($bands) as $b)<td class="num r">{{ $grid[$b]['male'] }}</td><td class="num r">{{ $grid[$b]['female'] }}</td>@endforeach<td class="num r">{{ collect($grid)->sum(fn ($s) => $s['male'] + $s['female']) }}</td></tr>
      @endforeach
    </tbody>
  </table>
  <h2>Diagnoses of those discharged</h2>
  <table class="rows">
    <tbody>
      @forelse($report['diagnoses'] as $d)
        <tr><td>{{ $d['label'] }}</td><td class="num r">{{ $d['total'] }}</td></tr>
      @empty
        <tr><td class="muted">None coded.</td><td></td></tr>
      @endforelse
    </tbody>
  </table>
</x-pdf.document>
