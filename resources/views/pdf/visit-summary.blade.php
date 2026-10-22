@php($set = app(\App\Support\HospitalSettings::class))
@php($patient = $summary['patient'])
@php($visit = $summary['visit'])
@php($qty = fn ($q) => rtrim(rtrim(number_format((float) $q, 2, '.', ''), '0'), '.'))
{{--
  The patient summary (Settings, Your practice, "Visit report shows"; docs/documents.md).
  What the patient takes home: the treatment done, how to take the medicine,
  the bill and what is left to pay, and when to come back. Nothing of the
  clinic's own record: no history, examination, findings, diagnosis, notes
  or chart.
--}}
<x-pdf.document
  :patient="$patient"
  title="Visit summary"
  :reference="$visit->visit_no"
  :meta="['Visit date' => $visit->created_at->format('d M Y')]">

  <table class="party">
    <tr>
      <td style="width:40%;">
        <span class="k">Patient</span>
        <span class="v big">{{ $patient?->full_name ?? '' }}</span>
        <span class="v muted num small">{{ $patient?->patient_no }}</span>
      </td>
      <td style="width:30%;">
        <span class="k">Age / Sex</span>
        <span class="v">{{ $summary['age'] ?? '' }} · {{ $patient?->sex?->label() ?? '' }}</span>
      </td>
      <td style="width:30%;">
        <span class="k">Seen by</span>
        <span class="v">{{ $visit->doctor?->name ?? '' }}</span>
        <span class="v muted small">{{ $visit->created_at->format('d M Y') }}</span>
      </td>
    </tr>
  </table>

  @if($summary['treatment'] !== [])
    <h2>Treatment done</h2>
    <table class="rows">
      <thead><tr><th style="width:70%;">Treatment</th><th style="width:30%;">Tooth</th></tr></thead>
      <tbody>
        @foreach($summary['treatment'] as $done)
          <tr><td>{{ $done['name'] }}</td><td class="num">{{ $done['tooth'] ?? '' }}</td></tr>
        @endforeach
      </tbody>
    </table>
  @endif

  @if($summary['dosage']->isNotEmpty())
    <h2>Dosage</h2>
    <table class="rows">
      <thead>
        <tr>
          <th style="width:30%;">Medicine</th>
          <th style="width:16%;">Dose</th>
          <th style="width:20%;">How often</th>
          <th style="width:10%;">Days</th>
          <th style="width:24%;">Note</th>
        </tr>
      </thead>
      <tbody>
        @foreach($summary['dosage'] as $dose)
          <tr>
            <td>{{ $dose->drug_name }}</td>
            <td>{{ $dose->dosage }}</td>
            <td>{{ $dose->howOften() }}</td>
            <td class="num">{{ $dose->days }}</td>
            <td>{{ $dose->instructions }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  <h2>Bill</h2>
  @if($summary['lines'] !== [])
    <table class="rows">
      <thead><tr><th style="width:62%;">Item</th><th class="r" style="width:12%;">Qty</th><th class="r" style="width:26%;">Amount</th></tr></thead>
      <tbody>
        @foreach($summary['lines'] as $line)
          <tr>
            <td>{{ $line['name'] }}</td>
            <td class="r num">{{ $qty($line['quantity']) }}</td>
            <td class="r num">{{ $set->format($line['amount']) }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  <table class="sums">
    @if(bccomp($summary['totals']['discount'], '0', 2) > 0)
      <tr><td>Discount</td><td class="r">− {{ $set->format($summary['totals']['discount']) }}</td></tr>
    @endif
    @if(bccomp($summary['totals']['tax'], '0', 2) > 0)
      <tr><td>{{ $set->taxLabel() }}</td><td class="r">{{ $set->format($summary['totals']['tax']) }}</td></tr>
    @endif
    <tr class="grand"><td>Total</td><td class="r">{{ $set->format($summary['totals']['total']) }}</td></tr>
    <tr><td>Paid</td><td class="r">{{ $set->format($summary['totals']['paid']) }}</td></tr>
    <tr>
      <td><b>Balance</b></td>
      <td class="r"><b>{{ $set->format($summary['totals']['balance']) }}</b></td>
    </tr>
  </table>

  @if($summary['payments']->isNotEmpty())
    <h2 style="clear:both;">Payments</h2>
    <table class="rows">
      <thead><tr><th style="width:30%;">Date</th><th style="width:40%;">Method</th><th class="r" style="width:30%;">Amount</th></tr></thead>
      <tbody>
        @foreach($summary['payments'] as $payment)
          <tr>
            <td class="num">{{ $payment->created_at?->format('d M Y') }}</td>
            <td>{{ $payment->method?->label() }}</td>
            <td class="r num">{{ $set->format($payment->amount) }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  @if($summary['invoice'])
    <p class="muted small" style="clear:both;">Invoice {{ $summary['invoice']->invoice_no }}.</p>
  @endif

  @if($summary['appointments']->isNotEmpty())
    <h2 style="clear:both;">Next appointments</h2>
    <table class="rows">
      <thead><tr><th style="width:22%;">Date</th><th style="width:14%;">Time</th><th style="width:28%;">With</th><th style="width:36%;">Reason</th></tr></thead>
      <tbody>
        @foreach($summary['appointments'] as $appointment)
          <tr>
            <td class="num">{{ $appointment->scheduled_at->format('D d M Y') }}</td>
            <td class="num">{{ $appointment->scheduled_at->format('H:i') }}</td>
            <td>{{ $appointment->doctor?->name }}</td>
            <td>{{ $appointment->reason }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</x-pdf.document>
