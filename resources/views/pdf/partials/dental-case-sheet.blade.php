{{--
  The dental case sheet on the visit report, under the headings a dentist
  writes by hand: History, then (after the vitals) Examination, Diagnosis,
  Treatment done today, Plan and follow-up. $sheet is
  DentalCaseSheetService::sheet(); $part is "history" or "rest".
--}}
@php($sections = $sheet['sections'])

@if($part === 'history')
  <h2>History</h2>
  <table class="rows">
    <tbody>
      <tr><td style="width:26%;" class="muted">Reason for visit</td><td>{{ $visit->reason ?: '—' }}</td></tr>
    </tbody>
  </table>

  @foreach(['complaint', 'dental_history', 'medical', 'surgical', 'financial'] as $key)
    @continue(! isset($sections[$key]))
    @php($s = $sections[$key])
    @continue($key === 'financial' && $s['lines'] === [])
    <h3 class="sub">{{ $s['label'] }}
      @if($s['scope'] === 'patient' && $s['recorded_at'])
        <span style="text-transform:none; letter-spacing:0; font-weight:normal;">· {{ $s['confirmed'] ? 'confirmed' : 'updated' }} {{ \Illuminate\Support\Carbon::parse($s['recorded_at'])->format('d M Y') }}</span>
      @endif
    </h3>
    @if($s['lines'] === [] && $key === 'complaint' && filled($visit->complaints))
      <p>{!! nl2br(e($visit->complaints)) !!}</p>
    @elseif($s['lines'] === [])
      <p class="muted small">{{ $s['scope'] === 'patient' ? 'Not asked.' : 'Not recorded.' }}</p>
    @else
      <table class="rows">
        <tbody>
          @foreach($s['lines'] as $line)
            <tr><td style="width:26%;" class="muted">{{ $line['label'] }}</td><td>{!! nl2br(e($line['value'])) !!}</td></tr>
          @endforeach
        </tbody>
      </table>
    @endif
  @endforeach
@else
  {{-- ── Examination ─────────────────────────────────────────── --}}
  <h2>Examination</h2>
  @foreach(['extra_oral', 'intra_oral'] as $key)
    @php($s = $sections[$key])
    <h3 class="sub">{{ $s['label'] }}</h3>
    @if($s['lines'] === [])
      <p class="muted small">Not recorded.</p>
    @else
      <table class="rows">
        <tbody>
          @foreach($s['lines'] as $line)
            <tr><td style="width:26%;" class="muted">{{ $line['label'] }}</td><td>{{ $line['value'] }}</td></tr>
          @endforeach
        </tbody>
      </table>
    @endif
  @endforeach

  <h3 class="sub">Hard tissues</h3>
  @include('pdf.partials.dental-chart', ['chart' => $sheet['chart']])

  @if($sheet['xrays'] !== [] || $sheet['labs'] !== [])
    <h3 class="sub">Investigations</h3>
    <table class="rows">
      <tbody>
        @foreach($sheet['xrays'] as $x)
          <tr>
            <td style="width:26%;" class="muted">{{ $x['kind_label'] }} X-ray{{ $x['teeth'] ? ', '.implode(' ', $x['teeth']) : '' }}</td>
            <td>{{ $x['finding'] }}@if($x['image'])<span class="muted small"> · image held with the visit</span>@endif</td>
          </tr>
        @endforeach
        @foreach($sheet['labs'] as $lab)
          <tr>
            <td class="muted">{{ $lab['name'] }}</td>
            <td>{{ $lab['result'] !== null ? trim($lab['result'].' '.($lab['unit'] ?? '')) : $lab['status'] }}@if($lab['flag'])<span class="muted small"> · {{ $lab['flag'] }}</span>@endif</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  {{-- ── Diagnosis ───────────────────────────────────────────── --}}
  <h2>Diagnosis</h2>
  @if($sheet['diagnoses'] === [])
    <p class="muted small">{{ filled($visit->diagnosis) ? $visit->diagnosis : 'Not recorded.' }}</p>
  @else
    <table class="rows">
      <thead><tr><th style="width:16%;">Tooth</th><th>Diagnosis</th><th style="width:14%;">Code</th></tr></thead>
      <tbody>
        @foreach($sheet['diagnoses'] as $d)
          <tr>
            <td class="num">{{ $d['tooth'] !== null ? $d['tooth'].($d['surfaces'] ? ' '.$d['surfaces'] : '') : 'Mouth' }}</td>
            <td>{{ $d['label'] }}@if($d['suspected'])<span class="muted small"> · suspected</span>@endif</td>
            <td class="num">{{ $d['code'] }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  {{-- ── Treatment done today ────────────────────────────────── --}}
  <h2>Treatment done today</h2>
  @if($sheet['done_today'] === [])
    <p class="muted small">No procedure from the treatment plan was marked done on this visit.</p>
  @else
    <table class="rows">
      <thead><tr><th>Procedure</th><th style="width:16%;">Tooth</th><th style="width:26%;">By</th></tr></thead>
      <tbody>
        @foreach($sheet['done_today'] as $t)
          <tr>
            <td>{{ $t['procedure'] }}@if($t['notes'])<br><span class="muted small">{{ $t['notes'] }}</span>@endif</td>
            <td class="num">{{ $t['tooth'] ?? '—' }}{{ $t['surfaces'] ? ' '.$t['surfaces'] : '' }}</td>
            <td class="muted small">{{ $t['done_by'] }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
  @if($sections['treatment_notes']['lines'] !== [])
    <table class="rows">
      <tbody>
        @foreach($sections['treatment_notes']['lines'] as $line)
          <tr><td style="width:26%;" class="muted">{{ $line['label'] }}</td><td>{!! nl2br(e($line['value'])) !!}</td></tr>
        @endforeach
      </tbody>
    </table>
  @endif

  {{-- ── Plan and follow-up ──────────────────────────────────── --}}
  <h2>Plan and follow-up</h2>
  @if($sheet['plan'] !== [])
    <table class="rows">
      <thead><tr><th>Still to do</th><th style="width:16%;">Tooth</th><th style="width:20%;">Status</th></tr></thead>
      <tbody>
        @foreach($sheet['plan'] as $p)
          <tr>
            <td>{{ $p['procedure'] }}</td>
            <td class="num">{{ $p['tooth'] ?? '—' }}{{ $p['surfaces'] ? ' '.$p['surfaces'] : '' }}</td>
            <td class="muted small">{{ $p['status_label'] }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
  <table class="rows">
    <tbody>
      <tr>
        <td style="width:26%;" class="muted">Instructions given</td>
        <td>{{ collect($sheet['instructions'])->pluck('label')->implode(', ') ?: 'None recorded' }}</td>
      </tr>
      <tr>
        <td class="muted">Review</td>
        <td>
          @forelse($sheet['reviews_booked'] as $r)
            {{ \Illuminate\Support\Carbon::parse($r['scheduled_at'])->format('D d M Y H:i').($r['doctor'] ? ' with '.$r['doctor'] : '').($loop->last ? '' : ';') }}
          @empty
            Not booked
          @endforelse
        </td>
      </tr>
      <tr>
        <td class="muted">Next check-up</td>
        <td>{{ $sheet['next_recall_on'] ? \Illuminate\Support\Carbon::parse($sheet['next_recall_on'])->format('d M Y') : 'Not set' }}</td>
      </tr>
    </tbody>
  </table>
@endif
