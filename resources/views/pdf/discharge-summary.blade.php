@php($set = app(\App\Support\HospitalSettings::class))
<x-pdf.document
  title="Discharge summary"
  :reference="'ADM-'.str_pad((string) $admission->id, 6, '0', STR_PAD_LEFT)"
  :meta="[
      'Discharged' => $admission->discharged_at?->format('d M Y H:i'),
      'Status' => $admission->status->label(),
  ]">

  <table class="party">
    <tr>
      <td>
        <span class="k">Patient</span>
        <span class="v big">{{ $admission->patient?->full_name ?? '—' }}</span>
        <span class="v muted num small">{{ $admission->patient?->patient_no }}</span>
      </td>
      <td>
        <span class="k">Age / Sex</span>
        <span class="v">
          {{ $admission->patient?->dob?->age !== null ? $admission->patient->dob->age.' yrs' : '—' }}
          · {{ $admission->patient?->sex?->label() ?? '—' }}
        </span>
      </td>
      <td>
        <span class="k">Under the care of</span>
        <span class="v">{{ $admission->admittingDoctor?->name ?? '—' }}</span>
      </td>
    </tr>
  </table>

  <h2>The stay</h2>

  <table class="rows">
    <tbody>
      <tr>
        <td style="width:34%;" class="muted">Ward and bed</td>
        <td>{{ $admission->bed?->ward?->name ?? '—' }} · {{ $admission->bed?->name ?? $admission->bed?->code ?? '—' }}</td>
      </tr>
      <tr><td class="muted">Admitted</td><td class="num">{{ $admission->admitted_at->format('d M Y H:i') }}</td></tr>
      <tr><td class="muted">Discharged</td><td class="num">{{ $admission->discharged_at?->format('d M Y H:i') ?? '—' }}</td></tr>
      <tr><td class="muted">Length of stay</td><td class="num">{{ $admission->nights() }} {{ Str::plural('night', $admission->nights()) }}</td></tr>
      <tr><td class="muted">Bed charge</td><td class="num">{{ $set->format($admission->bed_charge_total) }}</td></tr>
    </tbody>
  </table>

  <h2>Reason for admission</h2>
  <p>{{ $admission->reason ?? '—' }}</p>

  <h2>Discharge notes</h2>
  <p class="note">{!! nl2br(e($admission->discharge_notes ?? '—')) !!}</p>

  <table class="party" style="margin-top:26px;">
    <tr>
      <td style="width:50%;">
        <span class="k">Discharged by</span>
        <span class="v">{{ $admission->admittingDoctor?->name ?? '—' }}</span>
      </td>
      <td style="width:50%;">
        <span class="k">Signature</span>
        <span class="v" style="border-bottom:.5px solid #cfd8de; display:block; height:22px;"></span>
      </td>
    </tr>
  </table>

  <p class="foot-note muted small">
    Bed charges for this stay have been consolidated onto the patient’s invoice.
  </p>
</x-pdf.document>
