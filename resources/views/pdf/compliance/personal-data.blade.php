{{-- Everything the clinic holds about one patient, for a request to see it (App\Services\Compliance\PersonalDataExport). Plain. --}}
<x-pdf.document title="Personal data held about you" :reference="$patient->patient_no" :meta="['Patient' => $patient->full_name, 'Prepared' => now()->format('j M Y H:i')]" accent="black">
  <style>
    .pd { width: 100%; border-collapse: collapse; margin: 0 0 8px; }
    .pd td { border-bottom: .5px solid silver; padding: 2px 4px; vertical-align: top; font-size: 9px; }
    .pd td.k { width: 32%; color: dimgray; }
    .pd-intro { margin: 0 0 8px; }
  </style>
  <p class="pd-intro">
    This is a copy of the personal data this clinic holds about you, as you asked under the Data Protection and Privacy Act 2019.
    Each section is one kind of record. If anything is wrong, tell the clinic and it will be corrected.
  </p>
  @foreach($sections as $section)
    <h2>{{ $section['label'] }} ({{ count($section['rows']) }})</h2>
    @foreach($section['rows'] as $row)
      <table class="pd">
        @foreach($row as $label => $value)
          <tr><td class="k">{{ $label }}</td><td>{{ $value }}</td></tr>
        @endforeach
      </table>
    @endforeach
  @endforeach
</x-pdf.document>
