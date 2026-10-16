@php($set = app(\App\Support\HospitalSettings::class))
<x-pdf.document
  title="Treatment quote"
  :reference="$patient->patient_no"
  :meta="[
      'Date' => now()->format('d M Y'),
      'Valid for' => '30 days',
  ]">

  <table class="party">
    <tr>
      <td style="width:62%;">
        <span class="k">Prepared for</span>
        <span class="v big">{{ $patient->full_name }}</span>
        <span class="v muted num small">{{ $patient->patient_no }}</span>
      </td>
      <td style="width:38%;" class="r">
        <span class="k">Estimated total</span>
        <span class="headline">{{ $set->format($quote['total']) }}</span>
      </td>
    </tr>
  </table>

  <h2>Planned treatment</h2>

  <table class="rows">
    <thead><tr><th>Procedure</th><th>Tooth</th><th>Status</th><th class="r">Price</th></tr></thead>
    <tbody>
      @foreach($quote['items'] as $item)
        <tr>
          <td>{{ $item->service?->name }}@if($item->notes)<br><span class="muted small">{{ $item->notes }}</span>@endif</td>
          <td class="num">{{ $item->tooth ?? '' }} {{ $item->surfaces }}</td>
          <td>{{ $item->status->label() }}</td>
          <td class="num r">{{ $set->format($item->price) }}</td>
        </tr>
      @endforeach
      <tr><td colspan="3"><b>Total</b></td><td class="num r"><b>{{ $set->format($quote['total']) }}</b></td></tr>
    </tbody>
  </table>

  <p class="muted small">This is an estimate. Treatment may change once it starts; anything different is agreed with you first. Prices are charged as each procedure is done.</p>
</x-pdf.document>
