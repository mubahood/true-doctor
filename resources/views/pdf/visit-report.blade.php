@php($set = app(\App\Support\HospitalSettings::class))
@php($patient = $report['patient'])
@php($visit = $report['visit'])
{{--
  The whole of a visit, on paper (docs/documents.md).

  Written for a clinician who has never seen this system and cannot ask a
  follow-up question, so it is ordered the way they will read it: who this is
  and what would harm them, why they came, what was found, what was done, what
  came back, what they are taking — and only then, last, what it cost.
--}}
<x-pdf.document
  title="Visit report"
  :reference="$visit->visit_no"
  :meta="[
      'Seen' => $visit->created_at->format('d M Y H:i'),
      'Status' => $visit->stateLabel(),
  ]">

  {{-- ── Who ──────────────────────────────────────────────────────── --}}
  <table class="party">
    <tr>
      <td style="width:40%;">
        <span class="k">Patient</span>
        <span class="v big">{{ $patient?->full_name ?? '—' }}</span>
        <span class="v muted num small">{{ $patient?->patient_no }}</span>
      </td>
      <td style="width:30%;">
        <span class="k">Age / Sex</span>
        <span class="v">{{ $report['age'] ?? '—' }} · {{ $patient?->sex?->label() ?? '—' }}</span>
        <span class="v muted small">
          @if($patient?->dob)Born {{ $patient->dob->format('d M Y') }}@endif
        </span>
      </td>
      <td style="width:30%;">
        <span class="k">Attending</span>
        <span class="v">{{ $visit->doctor?->name ?? '—' }}</span>
        <span class="v muted small">{{ $visit->department?->name ?? 'No department recorded' }}</span>
      </td>
    </tr>
  </table>

  {{-- The two facts that change what the next clinician may safely do. They
       are placed before anything else, boxed, and printed even when empty —
       "none recorded" and "not asked" are different things, and a blank space
       cannot say which one this is. --}}
  <table class="rows" style="margin-top:14px;">
    <tbody>
      <tr>
        <td style="width:22%;" class="muted">Allergies</td>
        <td @if(filled($patient?->allergies)) style="font-weight:bold; color:#b3261e;" @endif>
          {{ filled($patient?->allergies) ? $patient->allergies : 'None recorded' }}
        </td>
        <td style="width:22%;" class="muted">Blood group</td>
        <td style="width:14%;" class="num">{{ $patient?->blood_type ?: '—' }}</td>
      </tr>
      <tr>
        <td class="muted">Ongoing conditions</td>
        <td colspan="3">{{ filled($patient?->chronic_conditions) ? $patient->chronic_conditions : 'None recorded' }}</td>
      </tr>
      <tr>
        <td class="muted">Contact</td>
        <td>
          {{ $patient?->phone_1 ?: '—' }}@if($patient?->email) · {{ $patient->email }}@endif
        </td>
        <td class="muted">Next of kin</td>
        <td>
          {{ $patient?->emergency_contact_name ?: '—' }}
          @if($patient?->emergency_contact_phone)<br><span class="muted small">{{ $patient->emergency_contact_phone }}</span>@endif
        </td>
      </tr>
      @if(filled($patient?->address) || filled($patient?->home_address))
        <tr>
          <td class="muted">Address</td>
          <td colspan="3">{{ $patient->address ?: $patient->home_address }}</td>
        </tr>
      @endif
    </tbody>
  </table>

  {{-- ── Why they came ────────────────────────────────────────────── --}}
  <h2>Presentation</h2>

  <table class="rows">
    <tbody>
      <tr>
        <td style="width:22%;" class="muted">Reason for visit</td>
        <td>{{ $visit->reason ?: '—' }}</td>
      </tr>
      @if(filled($visit->complaints))
        <tr><td class="muted">Complaints</td><td>{!! nl2br(e($visit->complaints)) !!}</td></tr>
      @endif
      @if(filled($visit->patient_remarks))
        <tr><td class="muted">In their own words</td><td>{!! nl2br(e($visit->patient_remarks)) !!}</td></tr>
      @endif
    </tbody>
  </table>

  @if($report['hasVitals'])
    <h2>Vitals @if($visit->vitals_recorded_at)<span class="muted small" style="text-transform:none; letter-spacing:0;">taken {{ $visit->vitals_recorded_at->format('d M Y H:i') }}</span>@endif</h2>

    <table class="rows">
      <tbody>
        {{-- Two pairs to a row: a vitals chart is scanned, not read. --}}
        @foreach(collect($report['vitals'])->chunk(2) as $pair)
          <tr>
            @foreach($pair as $reading)
              <td style="width:26%;" class="muted">{{ $reading['label'] }}</td>
              <td style="width:24%;" class="num">{{ $reading['value'] }}</td>
            @endforeach
            @if($pair->count() === 1)<td></td><td></td>@endif
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  {{-- ── What was found ───────────────────────────────────────────── --}}
  @if(filled($visit->diagnosis) || filled($visit->doctor_remarks))
    <h2>Clinical findings</h2>

    @if(filled($visit->diagnosis))
      <p><span class="muted small">Diagnosis</span><br>{!! nl2br(e($visit->diagnosis)) !!}</p>
    @endif

    @if(filled($visit->doctor_remarks))
      <p class="note">{!! nl2br(e($visit->doctor_remarks)) !!}</p>
    @endif
  @endif

  {{-- ── What was done ────────────────────────────────────────────── --}}
  @if($report['orders']->isNotEmpty())
    <h2>What was done</h2>

    @foreach($report['orders'] as $order)
      <table class="rows" style="margin-top:{{ $loop->first ? 0 : 12 }}px;">
        <thead>
          <tr>
            <th style="width:58%;">
              {{ $order->title }}
              <span class="muted" style="text-transform:none; letter-spacing:0; font-weight:normal;">
                · {{ $order->type->label() }}
              </span>
            </th>
            <th style="width:20%;">{{ $order->created_at->format('d M Y H:i') }}</th>
            <th class="r" style="width:22%;">{{ $order->status->label() }}</th>
          </tr>
        </thead>
        <tbody>
          @foreach($order->items as $item)
            @continue($item->status === \App\Enums\OrderItemStatus::Cancelled)
            <tr>
              <td>{{ $item->name }}</td>
              <td class="num muted">
                @if(bccomp((string) $item->quantity, '1', 2) !== 0)
                  × {{ rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.') }}
                @endif
              </td>
              <td class="r num">{{ $set->format($item->line_total) }}</td>
            </tr>
          @endforeach

          @if(filled($order->report))
            <tr>
              <td colspan="3">
                <span class="muted small">Report</span>
                <div class="note" style="margin-top:3px;">{!! nl2br(e($order->report)) !!}</div>
              </td>
            </tr>
          @endif

          {{-- Files cannot travel in a PDF, so the document says what exists
               and who to ask for it rather than pretending there was nothing. --}}
          @if($order->attachments->isNotEmpty())
            <tr>
              <td colspan="3" class="muted small">
                {{ $order->attachments->count() }}
                {{ Str::plural('file', $order->attachments->count()) }} held with this order:
                {{ $order->attachments->pluck('original_name')->filter()->implode(', ') ?: 'unnamed' }}
              </td>
            </tr>
          @endif

          @if($order->status === \App\Enums\OrderStatus::Cancelled && filled($order->cancel_reason))
            <tr><td colspan="3" class="muted small">Cancelled — {{ $order->cancel_reason }}</td></tr>
          @endif
        </tbody>
      </table>
    @endforeach
  @endif

  {{-- ── What came back ───────────────────────────────────────────── --}}
  @if($report['labs']->isNotEmpty())
    <h2>Laboratory results</h2>

    @foreach($report['labs'] as $lab)
      <table class="rows" style="margin-top:{{ $loop->first ? 0 : 12 }}px;">
        <thead>
          <tr>
            <th style="width:34%;">Test</th>
            <th class="r" style="width:16%;">Result</th>
            <th style="width:10%;">Unit</th>
            <th style="width:22%;">Reference</th>
            <th style="width:18%;">{{ $lab->status->label() }}</th>
          </tr>
        </thead>
        <tbody>
          @foreach($lab->items as $item)
            @php($abnormal = in_array($item->result_flag?->value, ['high', 'low', 'abnormal'], true))
            <tr>
              <td>{{ $item->name }}</td>
              <td class="r num" @if($abnormal) style="font-weight:bold; color:#b3261e;" @endif>
                {{ $item->result_value ?? '—' }}
              </td>
              <td class="muted">{{ $item->unit ?? '—' }}</td>
              <td class="num muted">{{ $item->reference_range ?? '—' }}</td>
              <td>
                @if($item->result_flag)
                  <span class="stamp {{ $abnormal ? 'bad' : 'ok' }}">{{ $item->result_flag->label() }}</span>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endforeach
  @endif

  @if($report['imaging']->isNotEmpty())
    <h2>Imaging</h2>

    @foreach($report['imaging'] as $study)
      <p style="margin-top:{{ $loop->first ? 0 : 12 }}px;">
        <span class="muted small">
          {{ $study->items->pluck('name')->implode(', ') ?: 'Study' }}
          · {{ $study->status->label() }}
          @if($study->reported_at)· reported {{ $study->reported_at->format('d M Y') }}@endif
        </span>
      </p>
      @if(filled($study->findings))
        <p><b>Findings.</b> {!! nl2br(e($study->findings)) !!}</p>
      @endif
      @if(filled($study->impression))
        <p class="note"><b>Impression.</b> {!! nl2br(e($study->impression)) !!}</p>
      @endif
    @endforeach
  @endif

  {{-- ── What they are taking ─────────────────────────────────────── --}}
  @if($report['prescriptions']->isNotEmpty())
    <h2>Medication prescribed</h2>

    <table class="rows">
      <thead>
        <tr>
          <th style="width:32%;">Drug</th>
          <th style="width:18%;">Dose</th>
          <th style="width:14%;">For</th>
          <th style="width:36%;">Instructions</th>
        </tr>
      </thead>
      <tbody>
        @foreach($report['prescriptions'] as $prescription)
          @foreach($prescription->doseItems as $dose)
            <tr>
              <td>{{ $dose->drug_name }}</td>
              <td class="num">{{ $dose->dosage ?: '—' }}</td>
              <td class="num">{{ $dose->days ? $dose->days.' '.Str::plural('day', $dose->days) : '—' }}</td>
              <td>{{ $dose->instructions ?: '—' }}</td>
            </tr>
          @endforeach
          @if(filled($prescription->notes))
            <tr><td colspan="4" class="muted small">{{ $prescription->notes }}</td></tr>
          @endif
        @endforeach
      </tbody>
    </table>
  @endif

  @if($report['dispensations']->isNotEmpty())
    <h2>Medication dispensed</h2>

    <table class="rows">
      <thead>
        <tr>
          <th style="width:52%;">Item</th>
          <th class="r" style="width:14%;">Qty</th>
          <th style="width:34%;">Dispensed</th>
        </tr>
      </thead>
      <tbody>
        @foreach($report['dispensations'] as $dispensation)
          @foreach($dispensation->items as $item)
            <tr>
              <td>{{ $item->name }}</td>
              <td class="r num">{{ rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.') }}</td>
              <td class="muted small">
                {{ $dispensation->created_at->format('d M Y') }}
                @if($dispensation->dispensedBy)· {{ $dispensation->dispensedBy->name }}@endif
              </td>
            </tr>
          @endforeach
        @endforeach
      </tbody>
    </table>
  @endif

  {{-- ── The stay ─────────────────────────────────────────────────── --}}
  @if($report['admissions']->isNotEmpty())
    <h2>Inpatient stay</h2>

    @foreach($report['admissions'] as $admission)
      <table class="rows" style="margin-top:{{ $loop->first ? 0 : 12 }}px;">
        <tbody>
          <tr>
            <td style="width:22%;" class="muted">Ward and bed</td>
            <td>{{ $admission->bed?->ward?->name ?? '—' }} · {{ $admission->bed?->name ?? '—' }}</td>
            <td style="width:18%;" class="muted">Nights</td>
            <td style="width:12%;" class="num">{{ $admission->nights() }}</td>
          </tr>
          <tr>
            <td class="muted">Admitted</td>
            <td class="num">{{ $admission->admitted_at->format('d M Y H:i') }}</td>
            <td class="muted">Discharged</td>
            <td class="num">{{ $admission->discharged_at?->format('d M Y H:i') ?? 'Still in' }}</td>
          </tr>
          @if(filled($admission->reason))
            <tr><td class="muted">Reason</td><td colspan="3">{{ $admission->reason }}</td></tr>
          @endif
          @if(filled($admission->discharge_notes))
            <tr>
              <td class="muted">Discharge notes</td>
              <td colspan="3">{!! nl2br(e($admission->discharge_notes)) !!}</td>
            </tr>
          @endif
        </tbody>
      </table>
    @endforeach
  @endif

  {{-- ── What it cost ─────────────────────────────────────────────── --}}
  <h2>Account</h2>

  <table class="sums" style="margin-top:0;">
    <tr><td>Charges</td><td class="r">{{ $set->format($report['totals']['subtotal']) }}</td></tr>
    @if(bccomp($report['totals']['discount'], '0', 2) > 0)
      <tr><td>Discount</td><td class="r">− {{ $set->format($report['totals']['discount']) }}</td></tr>
    @endif
    @if(bccomp($report['totals']['tax'], '0', 2) > 0)
      <tr><td>{{ $set->taxLabel() }}</td><td class="r">{{ $set->format($report['totals']['tax']) }}</td></tr>
    @endif
    <tr class="grand"><td>Total</td><td class="r">{{ $set->format($report['totals']['total']) }}</td></tr>
    @if(bccomp($report['totals']['paid'], '0', 2) > 0)
      <tr><td>Paid</td><td class="r">− {{ $set->format($report['totals']['paid']) }}</td></tr>
    @endif
    <tr>
      <td><b>{{ bccomp($report['totals']['balance'], '0', 2) > 0 ? 'Outstanding' : 'Settled' }}</b></td>
      <td class="r"><b>{{ $set->format($report['totals']['balance']) }}</b></td>
    </tr>
  </table>

  @if($report['invoice'])
    <p class="muted small" style="clear:both;">
      Invoiced as {{ $report['invoice']->invoice_no }} on
      {{ $report['invoice']->issued_at?->format('d M Y') }}.
      @if($report['payments']->isNotEmpty())
        {{ $report['payments']->count() }}
        {{ Str::plural('payment', $report['payments']->count()) }} recorded.
      @endif
    </p>
  @else
    <p class="muted small" style="clear:both;">
      No invoice has been raised for this visit; the figures above are the bill
      as it stands.
    </p>
  @endif

  {{-- ── Who is answerable for it ─────────────────────────────────── --}}
  <table class="party" style="margin-top:30px;">
    <tr>
      <td style="width:50%;">
        <span class="k">Prepared by</span>
        <span class="v">{{ $visit->doctor?->name ?? '—' }}</span>
        <span class="v muted small">{{ $visit->department?->name }}</span>
      </td>
      <td style="width:50%;">
        <span class="k">Signature and stamp</span>
        <span class="v" style="border-bottom:.5px solid #cfd8de; display:block; height:26px;"></span>
      </td>
    </tr>
  </table>

  <p class="foot-note muted small">
    This report covers visit {{ $visit->visit_no }} only. It is released to the
    patient or to a clinician acting on their behalf; any onward disclosure is
    the recipient’s responsibility.
    @if($report['attachments'] > 0)
      {{ $report['attachments'] }} {{ Str::plural('file', $report['attachments']) }}
      held with this visit are not reproduced here and may be requested from the
      issuing facility.
    @endif
  </p>
</x-pdf.document>
