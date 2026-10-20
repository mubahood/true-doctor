{{--
  A dental visit's simple notes on the visit report (docs/dental.md, "Simple
  notes"): Presenting complaint and History, then (after the vitals) Oral
  examination, the chart and the treatment done today. A heading with
  nothing under it is left out. $notes is VisitReportService's `dentalNotes`;
  $part is "history" or "rest".
--}}
@if($part === 'history')
  @if($notes['complaint'])
    <h2>Presenting complaint</h2>
    <p>{!! nl2br(e($notes['complaint'])) !!}</p>
  @endif

  @if($notes['groups']['history']['lines'] !== [])
    <h2>{{ $notes['groups']['history']['label'] }}</h2>
    @foreach($notes['groups']['history']['lines'] as $line)
      <h3 class="sub">{{ $line['label'] }}</h3>
      <p>{!! nl2br(e($line['text'])) !!}</p>
    @endforeach
  @endif
@else
  @if($notes['groups']['examination']['lines'] !== [])
    <h2>{{ $notes['groups']['examination']['label'] }}</h2>
    @foreach($notes['groups']['examination']['lines'] as $line)
      <h3 class="sub">{{ $line['label'] }}</h3>
      <p>{!! nl2br(e($line['text'])) !!}</p>
    @endforeach
  @endif

  @if($notes['chart'] !== [])
    <h2>Dental chart</h2>
    @include('pdf.partials.dental-chart', ['chart' => $notes['chart']])
  @endif

  @if($notes['done_today'] !== [])
    <h2>Treatment done</h2>
    <table class="rows">
      <thead><tr><th>Procedure</th><th style="width:16%;">Tooth</th><th style="width:26%;">By</th></tr></thead>
      <tbody>
        @foreach($notes['done_today'] as $t)
          <tr>
            <td>{{ $t['procedure'] }}@if($t['notes'])<br><span class="muted small">{{ $t['notes'] }}</span>@endif</td>
            <td class="num">{{ $t['tooth'] ?? '' }}{{ $t['surfaces'] ? ' '.$t['surfaces'] : '' }}</td>
            <td class="muted small">{{ $t['done_by'] }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
@endif
