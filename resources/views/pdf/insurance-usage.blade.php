@php($set = app(\App\Support\HospitalSettings::class))
@php($provider = $report['provider'])
{{-- The statement the hospital hands the insurer: who spent what, and what is
     left of the float that was meant to pay for it (docs/cards.md). --}}
<x-pdf.document
  title="Usage statement"
  :reference="$provider->code ?: null"
  :meta="[
      'Insurer' => $provider->name,
      'Period' => $report['from']->format('d M Y').' — '.$report['to']->format('d M Y'),
  ]">

  <table class="party">
    <tr>
      <td>
        <span class="k">Prepared for</span>
        <span class="v big">{{ $provider->name }}</span>
        @if($provider->contact_person)
          <span class="v muted small">Attn. {{ $provider->contact_person }}</span>
        @endif
      </td>
      <td>
        <span class="k">Members with activity</span>
        <span class="v">{{ count($report['members']) }}</span>
      </td>
      <td class="r">
        <span class="k">Total usage</span>
        <span class="v big num">{{ $set->format($report['net']) }}</span>
      </td>
    </tr>
  </table>

  <h2>Usage by member</h2>

  <table class="rows">
    <thead>
      <tr>
        <th style="width:30%;">Member</th>
        <th style="width:16%;">Patient no.</th>
        <th style="width:16%;">Member no.</th>
        <th style="width:16%;">Card</th>
        <th class="r" style="width:8%;">Items</th>
        <th class="r" style="width:14%;">Spent</th>
      </tr>
    </thead>
    <tbody>
      @forelse($report['members'] as $row)
        <tr>
          <td>{{ $row['patient'] }}</td>
          <td class="num muted">{{ $row['patient_no'] }}</td>
          <td class="num muted">{{ $row['member_no'] ?? '—' }}</td>
          <td class="num muted">{{ $row['card'] }}</td>
          <td class="r num">{{ $row['entries'] }}</td>
          <td class="r num">{{ $set->format($row['spent']) }}</td>
        </tr>
      @empty
        <tr><td colspan="6" class="muted">Nothing was spent on this insurer’s cards in this period.</td></tr>
      @endforelse
    </tbody>
  </table>

  <table class="sums">
    <tr><td>Charged</td><td class="r">{{ $set->format($report['spent']) }}</td></tr>
    @if(bccomp($report['refunded'], '0', 2) > 0)
      <tr><td>Refunded</td><td class="r">− {{ $set->format($report['refunded']) }}</td></tr>
    @endif
    <tr class="grand"><td>Total usage</td><td class="r">{{ $set->format($report['net']) }}</td></tr>
  </table>

  {{-- The other half of the account. The period figures and the standing
       position are deliberately labelled apart: one is what happened in the
       window, the other is where things stand today. --}}
  <h2>The account</h2>

  <table class="rows">
    <tbody>
      <tr>
        <td style="width:62%;" class="muted">Deposits received in this period</td>
        <td class="r num">{{ $set->format($report['deposits']) }}</td>
      </tr>
      <tr>
        <td class="muted">Cleared onto member cards in this period</td>
        <td class="r num">{{ $set->format($report['settlements']) }}</td>
      </tr>
      <tr>
        <td class="muted">Float remaining <span class="small">(as at today)</span></td>
        <td class="r num">{{ $set->format($report['float']) }}</td>
      </tr>
      <tr>
        <td class="muted">Owed by members <span class="small">(as at today)</span></td>
        <td class="r num">{{ $set->format($report['outstanding']) }}</td>
      </tr>
    </tbody>
  </table>

  <p class="foot-note muted small">
    Usage figures cover the period stated above. The float and outstanding lines
    are the position as at the date this statement was generated.
  </p>
</x-pdf.document>
