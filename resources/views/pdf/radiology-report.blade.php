<x-pdf.document
  title="Radiology report"
  :reference="'RAD-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT)"
  :meta="[
      'Reported' => $order->reported_at?->format('d M Y H:i') ?? $order->created_at->format('d M Y'),
      'Status' => $order->status->label(),
  ]">

  <table class="party">
    <tr>
      <td>
        <span class="k">Patient</span>
        <span class="v big">{{ $order->patient?->full_name ?? '—' }}</span>
        <span class="v muted num small">{{ $order->patient?->patient_no }}</span>
      </td>
      <td>
        <span class="k">Age / Sex</span>
        <span class="v">
          {{ $order->patient?->dob?->age !== null ? $order->patient->dob->age.' yrs' : '—' }}
          · {{ $order->patient?->sex?->label() ?? '—' }}
        </span>
      </td>
      <td>
        <span class="k">Requested by</span>
        <span class="v">{{ $order->orderedBy?->name ?? '—' }}</span>
        <span class="v muted small">{{ $order->created_at->format('d M Y H:i') }}</span>
      </td>
    </tr>
  </table>

  <h2>Examinations</h2>

  <table class="rows">
    <thead>
      <tr><th style="width:70%;">Study</th><th style="width:30%;">Modality</th></tr>
    </thead>
    <tbody>
      @forelse($order->items as $item)
        <tr>
          <td>{{ $item->name }}</td>
          <td class="muted">{{ $item->modality ?? '—' }}</td>
        </tr>
      @empty
        <tr><td colspan="2" class="muted">No studies were recorded on this order.</td></tr>
      @endforelse
    </tbody>
  </table>

  @if(filled($order->clinical_notes))
    <h2>Clinical history</h2>
    <p class="note">{!! nl2br(e($order->clinical_notes)) !!}</p>
  @endif

  <h2>Findings</h2>
  <p>{!! nl2br(e($order->findings ?? '—')) !!}</p>

  {{-- The impression is what the referring clinician acts on, so it is set
       apart from the findings rather than running on from them. --}}
  <h2>Impression</h2>
  <p class="note">{!! nl2br(e($order->impression ?? '—')) !!}</p>

  <table class="party" style="margin-top:26px;">
    <tr>
      <td style="width:50%;">
        <span class="k">Reported by</span>
        <span class="v">{{ $order->reportedBy?->name ?? '—' }}</span>
      </td>
      <td style="width:50%;">
        <span class="k">Signature</span>
        <span class="v" style="border-bottom:.5px solid #cfd8de; display:block; height:22px;"></span>
      </td>
    </tr>
  </table>

  <p class="foot-note muted small">
    Valid only with an authorised radiologist’s signature.
  </p>
</x-pdf.document>
