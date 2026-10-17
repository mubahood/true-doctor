<x-pdf.document title="HMIS 033b: Weekly surveillance report" :reference="'Week '.$report['week']['number'].' of '.$report['week']['year']" :meta="['From' => $report['week']['from'], 'To' => $report['week']['to'], 'Form' => $version]">
  <table class="rows">
    <thead><tr><th>Condition</th><th class="r">Cases</th><th class="r">Deaths</th></tr></thead>
    <tbody>
      @foreach($report['conditions'] as $c)
        <tr><td>{{ $c['label'] }}</td><td class="num r">{{ $c['cases'] }}</td><td class="num r">{{ $c['deaths'] }}</td></tr>
      @endforeach
    </tbody>
  </table>
  <p class="foot-note muted small">Cases are visits this week with the condition coded; deaths are admissions that ended in death this week with it coded.</p>
</x-pdf.document>
