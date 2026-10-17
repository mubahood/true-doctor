@php($patient = $visit->patient)
{{-- What was prescribed on one visit, for the patient to carry or show at a
     pharmacy (roadmap 05 §4). The same drugs and doses as the visit report's
     "Medication prescribed", on a page of its own. --}}
<x-pdf.document
  :patient="$patient"
  title="Prescription"
  :reference="$visit->visit_no"
  :meta="[
      'Date' => $visit->created_at->format('d M Y'),
      'Prescriber' => $visit->doctor?->name,
  ]">

  <table class="party">
    <tr>
      <td>
        <span class="k">Patient</span>
        <span class="v big">{{ $patient?->full_name ?? '—' }}</span>
        <span class="v muted num small">{{ $patient?->patient_no }}</span>
      </td>
      <td>
        <span class="k">Age / Sex</span>
        <span class="v">
          {{ $patient?->dob?->age !== null ? $patient->dob->age.' yrs' : '—' }}
          · {{ $patient?->sex?->label() ?? '—' }}
        </span>
      </td>
      <td>
        <span class="k">Visit</span>
        <span class="v num">{{ $visit->visit_no }}</span>
        <span class="v muted small">{{ $visit->created_at->format('d M Y H:i') }}</span>
      </td>
    </tr>
  </table>

  <h2>Medication</h2>

  <table class="rows">
    <thead>
      <tr><th>Drug</th><th>Dose</th><th>For</th><th>Instructions</th></tr>
    </thead>
    <tbody>
      @foreach($visit->prescriptions as $prescription)
        @foreach($prescription->doseItems as $dose)
          <tr>
            <td>{{ $dose->drug_name }}</td>
            <td class="num">{{ $dose->dosage ?: '—' }}</td>
            <td class="num">{{ $dose->days ? $dose->days.' '.\Illuminate\Support\Str::plural('day', $dose->days) : '—' }}</td>
            <td>{{ $dose->instructions ?: '—' }}</td>
          </tr>
        @endforeach
        @if(filled($prescription->notes))
          <tr><td colspan="4" class="muted small">{{ $prescription->notes }}</td></tr>
        @endif
      @endforeach
    </tbody>
  </table>

  <p class="foot-note muted small">Take every dose as written and finish the course unless your doctor says otherwise. Questions about this prescription: call the clinic.</p>
</x-pdf.document>
