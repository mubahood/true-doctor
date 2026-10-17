<x-pdf.thermal :width="$width" :title="'Ticket '.$visit->visit_no">
  <div class="c small">Your number</div>
  <div class="c big">{{ $number }}</div>
  <div class="c">{{ $visit->patient?->first_name }}</div>
  <div class="rule"></div>
  <table class="small">
    @if($visit->department)<tr><td>Department</td><td class="r">{{ $visit->department->name }}</td></tr>@endif
    @if($visit->doctor)<tr><td>Doctor</td><td class="r">{{ $visit->doctor->name }}</td></tr>@endif
    <tr><td>Arrived</td><td class="r">{{ $visit->created_at?->format('d M Y H:i') }}</td></tr>
    <tr><td>Visit</td><td class="r num">{{ $visit->visit_no }}</td></tr>
  </table>
  <div class="rule"></div>
  <div class="c small">Please wait to be called.</div>
</x-pdf.thermal>
