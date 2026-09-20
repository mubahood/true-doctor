<x-pdf.document
  title="Laboratory report"
  :reference="'LAB-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT)"
  :meta="[
      'Reported' => $order->completed_at?->format('d M Y H:i') ?? $order->created_at->format('d M Y'),
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

  <h2>Results</h2>

  <table class="rows">
    <thead>
      <tr>
        <th style="width:36%;">Test</th>
        <th class="r" style="width:16%;">Result</th>
        <th style="width:10%;">Unit</th>
        <th style="width:22%;">Reference range</th>
        <th style="width:16%;">Flag</th>
      </tr>
    </thead>
    <tbody>
      @forelse($order->items as $item)
        @php($abnormal = in_array($item->result_flag?->value, ['high', 'low', 'abnormal'], true))
        <tr>
          <td>{{ $item->name }}</td>
          {{-- The one thing a clinician reads first, so it is the one thing
               set apart: mono, right-aligned against its range, bold and red
               when it falls outside it. --}}
          <td class="r num" @if($abnormal) style="font-weight:bold; color:#b3261e;" @endif>
            {{ $item->result_value ?? '—' }}
          </td>
          <td class="muted">{{ $item->unit ?? '—' }}</td>
          <td class="num muted">{{ $item->reference_range ?? '—' }}</td>
          <td>
            @if($item->result_flag)
              <span class="stamp {{ $abnormal ? 'bad' : 'ok' }}">{{ $item->result_flag->label() }}</span>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="5" class="muted">No tests were recorded on this order.</td></tr>
      @endforelse
    </tbody>
  </table>

  @if(filled($order->clinical_notes))
    <h2>Clinical notes</h2>
    <p class="note">{!! nl2br(e($order->clinical_notes)) !!}</p>
  @endif

  <p class="foot-note muted small">
    Results relate only to the samples tested. This report is valid only with an
    authorised signature.
  </p>
</x-pdf.document>
