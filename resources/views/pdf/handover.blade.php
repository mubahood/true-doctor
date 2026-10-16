<x-pdf.document
  title="Shift handover"
  :reference="strtoupper(substr($handover->uuid, 0, 8))"
  :meta="[
      'Ward' => $handover->ward?->name ?? '-',
      'Shift' => ucfirst($handover->shift),
      'Written' => $handover->created_at?->format('d M Y H:i'),
      'By' => $handover->author?->name ?? '-',
  ]">

  @forelse($handover->notes as $note)
    <table class="party" style="margin-top:8px;">
      <tr>
        <td style="width:70%;">
          <span class="v big">{{ $note['patient']['name'] ?? '-' }}</span>
          <span class="v muted num small">{{ $note['patient']['patient_no'] ?? '' }}</span>
        </td>
        <td style="width:30%;" class="r"><span class="k">Bed</span><span class="v">{{ $note['bed'] ?? '-' }}</span></td>
      </tr>
    </table>
    <table class="rows">
      <tbody>
        <tr><td style="width:22%;" class="muted">Situation</td><td>{{ $note['situation'] ?? '' }}</td></tr>
        <tr><td class="muted">Background</td><td>{{ $note['background'] ?? '' }}</td></tr>
        <tr><td class="muted">Assessment</td><td>{{ $note['assessment'] ?? '' }}</td></tr>
        <tr><td class="muted">Recommendation</td><td>{{ $note['recommendation'] ?? '' }}</td></tr>
      </tbody>
    </table>
  @empty
    <p class="muted">No patients were handed over.</p>
  @endforelse

  <p class="foot-note muted small">
    @if($handover->acknowledged_at)
      Taken over by {{ $handover->acknowledgedBy?->name ?? '-' }} on {{ $handover->acknowledged_at->format('d M Y H:i') }}.
    @else
      Not yet acknowledged by the incoming shift.
    @endif
  </p>
</x-pdf.document>
