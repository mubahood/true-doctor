{{--
  What the patient takes home after treatment: the instructions ticked on the
  case sheet, in plain words, and when to come back. Printed from the visit,
  or opened by the app (docs/api/dental.md).
--}}
<x-pdf.document
  :patient="$patient"
  title="Aftercare"
  :reference="$visit->visit_no"
  :meta="['Date' => $visit->created_at->format('d M Y')]">

  <table class="party">
    <tr>
      <td style="width:60%;">
        <span class="k">For</span>
        <span class="v big">{{ $patient->full_name }}</span>
        <span class="v muted num small">{{ $patient->patient_no }}</span>
      </td>
      <td style="width:40%;">
        <span class="k">Treated by</span>
        <span class="v">{{ $visit->doctor?->name ?? '—' }}</span>
      </td>
    </tr>
  </table>

  @if($sheet['done_today'] !== [])
    <h2>Done today</h2>
    <p>
      @foreach($sheet['done_today'] as $t){{ $t['procedure'] }}@if($t['tooth']) (tooth {{ $t['tooth'] }})@endif{{ $loop->last ? '.' : ', ' }}@endforeach
    </p>
  @endif

  @forelse($sheet['instructions'] as $block)
    <h2>{{ $block['label'] }}</h2>
    <ol>
      @foreach($block['lines'] as $line)<li>{{ $line }}</li>@endforeach
    </ol>
  @empty
    <p class="muted">No instructions were recorded on this visit.</p>
  @endforelse

  <h2>Coming back</h2>
  <p>
    @forelse($sheet['reviews_booked'] as $r)
      Your review is on <b>{{ \Illuminate\Support\Carbon::parse($r['scheduled_at'])->format('l d F Y \a\t H:i') }}</b>@if($r['doctor']) with {{ $r['doctor'] }}@endif.<br>
    @empty
      @if($sheet['review'])Please come back in {{ $sheet['review']['days'] }} {{ \Illuminate\Support\Str::plural('day', $sheet['review']['days']) }}.<br>@endif
    @endforelse
    @if($sheet['next_recall_on'])Your next check-up is due on <b>{{ \Illuminate\Support\Carbon::parse($sheet['next_recall_on'])->format('d F Y') }}</b>.<br>@endif
    If you have severe pain, swelling that spreads, a fever or bleeding that does not stop, call the clinic straight away.
  </p>
</x-pdf.document>
