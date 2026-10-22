@php($set = app(\App\Support\HospitalSettings::class))
@php($patient = $summary['patient'])
@php($visit = $summary['visit'])
@php($qty = fn ($q) => rtrim(rtrim(number_format((float) $q, 2, '.', ''), '0'), '.'))
{{--
  The patient summary (Settings, Your practice, "Visit report shows"; docs/documents.md).
  What the patient takes home: who they are, the dentist, the oral examination,
  how to take the medicine, the treatment plan and what was done, the receipt,
  when to come back, and who prepared it. Nothing of the clinic's own record:
  no history, internal notes or vitals.
--}}
<x-pdf.document
  :patient="$patient"
  title="Visit summary"
  :reference="$visit->visit_no"
  :meta="['Visit date' => $visit->created_at->format('d M Y')]">

  <table class="party">
    <tr>
      <td style="width:34%;">
        <span class="k">Patient</span>
        <span class="v big">{{ $patient?->full_name ?? '' }}</span>
        <span class="v muted num small">{{ $patient?->patient_no }}</span>
      </td>
      <td style="width:20%;">
        <span class="k">Age / Sex</span>
        <span class="v">{{ $summary['age'] ?? '' }} · {{ $patient?->sex?->label() ?? '' }}</span>
      </td>
      <td style="width:20%;">
        <span class="k">Contact</span>
        <span class="v num">{{ $summary['phone'] ?? '' }}</span>
      </td>
      <td style="width:26%;">
        <span class="k">{{ $summary['dental'] ? 'Dentist' : 'Seen by' }}</span>
        <span class="v">{{ $visit->doctor?->name ?? '' }}</span>
        <span class="v muted small">{{ $visit->created_at->format('d M Y') }}</span>
      </td>
    </tr>
  </table>

  @if($summary['examination'] !== [])
    <h2>Oral examination</h2>
    <table class="rows">
      <tbody>
        @foreach($summary['examination'] as $line)
          <tr><td style="width:28%;" class="muted">{{ $line['label'] }}</td><td>{!! nl2br(e($line['text'])) !!}</td></tr>
        @endforeach
      </tbody>
    </table>
  @endif

  @if($summary['dosage']->isNotEmpty())
    <h2>Prescription</h2>
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

  @if($summary['plan'] !== [])
    <h2>Treatment plan</h2>
    <table class="rows">
      <thead><tr><th style="width:56%;">Treatment</th><th style="width:22%;">Tooth</th><th style="width:22%;">Status</th></tr></thead>
      <tbody>
        @foreach($summary['plan'] as $item)
          <tr><td>{{ $item['name'] }}</td><td class="num">{{ $item['tooth'] ?? '' }}</td><td>{{ $item['status'] }}</td></tr>
        @endforeach
      </tbody>
    </table>
  @endif

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

  <h2>Receipt</h2>
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
      <thead><tr><th style="width:24%;">Date</th><th style="width:22%;">Receipt no</th><th style="width:26%;">Method</th><th class="r" style="width:28%;">Amount</th></tr></thead>
      <tbody>
        @foreach($summary['payments'] as $payment)
          <tr>
            <td class="num">{{ $payment->created_at?->format('d M Y') }}</td>
            <td class="num">{{ $payment->receiptNo() }}</td>
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

  <table class="party" style="margin-top:24px; clear:both;">
    <tr>
      <td style="width:50%;">
        <span class="k">Prepared by</span>
        <span class="v">{{ $summary['prepared_by'] ?? '' }}</span>
        <span class="v muted small">{{ $summary['prepared_at']->format('d M Y H:i') }}</span>
      </td>
      <td style="width:50%;"></td>
    </tr>
  </table>
</x-pdf.document>
