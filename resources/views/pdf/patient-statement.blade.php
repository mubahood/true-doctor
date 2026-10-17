@php($set = app(\App\Support\HospitalSettings::class))
{{-- A patient's statement of account (PatientController@statement): every
     invoice, every payment, what is still owed and how old it is. The same
     figures as the patient's Billing tab, from PatientSummaryService. --}}
<x-pdf.document
  :patient="$patient"
  title="Statement of account"
  :reference="$patient->patient_no"
  :meta="array_filter([
      'As at' => $asAt->format('d M Y'),
      'Patient' => $patient->full_name,
  ])">

  <table class="party">
    <tr>
      <td>
        <span class="k">Account of</span>
        <span class="v big">{{ $patient->full_name }}</span>
        <span class="v muted num small">{{ $patient->patient_no }}</span>
      </td>
      <td>
        <span class="k">As at</span>
        <span class="v num">{{ $asAt->format('d M Y') }}</span>
      </td>
      <td class="r">
        <span class="k">Balance due</span>
        <span class="v big num">{{ $set->format($s['balance_due']) }}</span>
      </td>
    </tr>
  </table>

  <h2>Invoices</h2>
  <table class="rows">
    <thead>
      <tr>
        <th style="width:16%;">Date</th>
        <th style="width:28%;">Invoice</th>
        <th class="r" style="width:18%;">Total</th>
        <th class="r" style="width:19%;">Paid</th>
        <th class="r" style="width:19%;">Balance</th>
      </tr>
    </thead>
    <tbody>
      @forelse($s['invoices'] as $i)
        <tr>
          <td>{{ \Illuminate\Support\Carbon::parse($i['date'])->format('d M Y') }}</td>
          <td class="num">{{ $i['invoice_no'] }}</td>
          <td class="r num">{{ $set->format($i['total']) }}</td>
          <td class="r num">{{ $set->format($i['amount_paid']) }}</td>
          <td class="r num">{{ $set->format($i['balance']) }}</td>
        </tr>
      @empty
        <tr><td colspan="5" class="muted">No invoices have been raised for this patient.</td></tr>
      @endforelse
    </tbody>
  </table>

  <h2>Payments</h2>
  <table class="rows">
    <thead>
      <tr>
        <th style="width:16%;">Date</th>
        <th style="width:24%;">Invoice</th>
        <th style="width:22%;">Method</th>
        <th style="width:20%;">Reference</th>
        <th class="r" style="width:18%;">Amount</th>
      </tr>
    </thead>
    <tbody>
      @forelse($s['payments'] as $p)
        <tr>
          <td>{{ \Illuminate\Support\Carbon::parse($p['date'])->format('d M Y') }}</td>
          <td class="num">{{ $p['invoice_no'] }}</td>
          <td>{{ $p['method'] }}</td>
          <td class="num">{{ $p['reference'] ?? '' }}</td>
          <td class="r num">{{ $set->format($p['amount']) }}</td>
        </tr>
      @empty
        <tr><td colspan="5" class="muted">No payments have been received.</td></tr>
      @endforelse
    </tbody>
  </table>

  <table class="sums">
    <tr><td>Total billed</td><td class="r">{{ $set->format($s['billed']) }}</td></tr>
    <tr><td>Total paid</td><td class="r">− {{ $set->format($s['paid']) }}</td></tr>
    <tr class="grand"><td>Balance due</td><td class="r">{{ $set->format($s['balance_due']) }}</td></tr>
  </table>

  @if(bccomp($s['balance_due'], '0', 2) > 0)
    <h2>How old the balance is</h2>
    <table class="rows">
      <thead>
        <tr>
          @foreach($s['aging']['buckets'] as $band => $amount)
            <th class="r">{{ $band }} days</th>
          @endforeach
        </tr>
      </thead>
      <tbody>
        <tr>
          @foreach($s['aging']['buckets'] as $amount)
            <td class="r num">{{ $set->format($amount) }}</td>
          @endforeach
        </tr>
      </tbody>
    </table>
  @else
    <p class="foot-note"><span class="stamp ok">Nothing owed</span></p>
  @endif
</x-pdf.document>
