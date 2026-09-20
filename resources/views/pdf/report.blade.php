@php($set = app(\App\Support\HospitalSettings::class))
{{-- The reports dashboard as a document.

     A screen answers "how are we doing" for whoever is sitting at it. This is
     the version that gets handed to a board, filed, and read a month later by
     somebody who was not there — so it carries the range it covers, the moment
     it was taken and who took it, because a page of figures with no dates on it
     is a page nobody can check.

     Period figures and standing positions are labelled apart throughout, the
     same way the insurer statement does it: revenue and nights happened inside
     the window; occupancy and stock are how things are right now. --}}
<x-pdf.document
  title="Report"
  :reference="$from->format('d M Y').' — '.$to->format('d M Y')"
  :meta="[
      'Generated' => $generatedAt->format('d M Y H:i'),
      'By' => $generatedBy,
  ]">

  <table class="party">
    <tr>
      <td>
        <span class="k">Revenue received</span>
        <span class="v big num">{{ $set->format($revenue['total']) }}</span>
        <span class="v muted small">{{ number_format($revenue['count']) }} payments in this period</span>
      </td>
      <td>
        <span class="k">Outstanding</span>
        <span class="v big num">{{ $set->format($outstanding['total']) }}</span>
        <span class="v muted small">{{ number_format($outstanding['count']) }} unpaid invoices, as at today</span>
      </td>
      <td class="r">
        <span class="k">Inpatient nights</span>
        <span class="v big num">{{ number_format($inpatient['nights']) }}</span>
        <span class="v muted small">{{ $set->format($inpatient['revenue']) }} billed</span>
      </td>
    </tr>
  </table>

  <h2>Revenue by method</h2>

  <table class="rows">
    <thead>
      <tr><th style="width:70%;">Method</th><th class="r">Amount</th></tr>
    </thead>
    <tbody>
      @forelse($revenue['by_method'] as $method => $amount)
        <tr>
          <td>{{ \App\Enums\PaymentMethod::tryFrom($method)?->label() ?? $method }}</td>
          <td class="r num">{{ $set->format($amount) }}</td>
        </tr>
      @empty
        <tr><td colspan="2" class="muted">No payments were taken in this period.</td></tr>
      @endforelse
    </tbody>
  </table>

  <table class="sums">
    <tr class="grand"><td>Received</td><td class="r">{{ $set->format($revenue['total']) }}</td></tr>
  </table>

  {{-- What is owed. Aged, because a balance raised this morning and one
       ninety days old are not the same problem. --}}
  <h2>Outstanding by age <span class="small muted">(as at today)</span></h2>

  <table class="rows">
    <thead>
      <tr><th style="width:70%;">Age of invoice</th><th class="r">Balance</th></tr>
    </thead>
    <tbody>
      @foreach($outstanding['buckets'] as $bucket => $amount)
        <tr>
          <td>{{ $bucket }}</td>
          <td class="r num">{{ $set->format($amount) }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <table class="sums">
    <tr class="grand"><td>Owed</td><td class="r">{{ $set->format($outstanding['total']) }}</td></tr>
  </table>

  <h2>Inpatient stays</h2>

  <table class="rows">
    <thead>
      <tr>
        <th style="width:52%;">Ward</th>
        <th class="r" style="width:24%;">Nights</th>
        <th class="r" style="width:24%;">Billed</th>
      </tr>
    </thead>
    <tbody>
      @forelse($inpatient['by_ward'] as $row)
        <tr>
          <td>{{ $row['ward'] }}</td>
          <td class="r num">{{ number_format($row['nights']) }}</td>
          <td class="r num">{{ $set->format($row['revenue']) }}</td>
        </tr>
      @empty
        <tr><td colspan="3" class="muted">Nobody was in a bed in this period.</td></tr>
      @endforelse
    </tbody>
  </table>

  <p class="small muted" style="margin-top:6px;">
    {{ number_format($inpatient['stays']) }} stays touched this period,
    {{ number_format($inpatient['discharged']) }} of them discharged in it.
    Ward figures count a stay to the ward its patient is in now, so a patient
    moved mid-stay counts where they ended up.
  </p>

  <h2>Top services by revenue</h2>

  <table class="rows">
    <thead>
      <tr>
        <th style="width:60%;">Service</th>
        <th class="r" style="width:16%;">Times</th>
        <th class="r" style="width:24%;">Charged</th>
      </tr>
    </thead>
    <tbody>
      @forelse(array_slice($serviceRevenue, 0, 20) as $row)
        <tr>
          <td>{{ $row['name'] }}</td>
          <td class="r num">{{ number_format($row['count']) }}</td>
          <td class="r num">{{ $set->format($row['revenue']) }}</td>
        </tr>
      @empty
        <tr><td colspan="3" class="muted">Nothing was billed in this period.</td></tr>
      @endforelse
    </tbody>
  </table>

  @if(count($serviceRevenue) > 20)
    <p class="small muted" style="margin-top:6px;">
      The 20 largest of {{ number_format(count($serviceRevenue)) }} billed lines.
    </p>
  @endif

  <h2>Doctor activity</h2>

  <table class="rows">
    <thead>
      <tr>
        <th style="width:60%;">Doctor</th>
        <th class="r" style="width:20%;">Visits</th>
        <th class="r" style="width:20%;">Appointments</th>
      </tr>
    </thead>
    <tbody>
      @forelse($productivity as $row)
        <tr>
          <td>{{ $row['doctor'] }}</td>
          <td class="r num">{{ number_format($row['visits']) }}</td>
          <td class="r num">{{ number_format($row['appointments']) }}</td>
        </tr>
      @empty
        <tr><td colspan="3" class="muted">No visits or appointments were recorded in this period.</td></tr>
      @endforelse
    </tbody>
  </table>

  {{-- Everything below is a standing position, not a period figure. --}}
  <h2>Beds and stock <span class="small muted">(as at today)</span></h2>

  <table class="rows">
    <tbody>
      <tr>
        <td style="width:62%;" class="muted">Beds occupied</td>
        <td class="r num">{{ $occupancy['occupied'] }} of {{ $occupancy['total'] }} ({{ $occupancy['rate'] }}%)</td>
      </tr>
      <tr>
        <td class="muted">Beds available</td>
        <td class="r num">{{ $occupancy['available'] }}</td>
      </tr>
      <tr>
        <td class="muted">Stock on hand, at cost</td>
        <td class="r num">{{ $set->format($valuation['total_value']) }}</td>
      </tr>
      <tr>
        <td class="muted">Items at or below reorder level</td>
        <td class="r num">{{ number_format($valuation['low_stock']) }}</td>
      </tr>
      <tr>
        <td class="muted">Items expiring within 90 days</td>
        <td class="r num">{{ number_format($valuation['expiring']) }}</td>
      </tr>
    </tbody>
  </table>

  <h2>Patients on the register <span class="small muted">(as at today)</span></h2>

  <table class="rows">
    <thead>
      <tr><th style="width:36%;">Sex</th><th class="r" style="width:14%;">Patients</th><th style="width:36%;">Age</th><th class="r" style="width:14%;">Patients</th></tr>
    </thead>
    <tbody>
      @php($sexes = array_keys($demographics['by_sex']))
      @php($ages = array_keys($demographics['by_age']))
      @for($i = 0; $i < max(count($sexes), count($ages)); $i++)
        <tr>
          <td>{{ isset($sexes[$i]) ? ucfirst($sexes[$i]) : '' }}</td>
          <td class="r num">{{ isset($sexes[$i]) ? number_format($demographics['by_sex'][$sexes[$i]]) : '' }}</td>
          <td>{{ $ages[$i] ?? '' }}</td>
          <td class="r num">{{ isset($ages[$i]) ? number_format($demographics['by_age'][$ages[$i]]) : '' }}</td>
        </tr>
      @endfor
    </tbody>
  </table>

  <table class="sums">
    <tr class="grand"><td>Patients</td><td class="r">{{ number_format($demographics['total']) }}</td></tr>
  </table>

  <p class="foot-note muted small">
    Revenue, inpatient nights, service and doctor figures cover the period stated
    above. Outstanding balances, occupancy, stock and the patient register are the
    position as at the moment this report was generated.
  </p>
</x-pdf.document>
