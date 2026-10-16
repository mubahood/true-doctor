@php
  $d = fn ($iso, $f = 'd M Y') => $iso ? \Illuminate\Support\Carbon::parse($iso)->format($f) : '—';
  $c = $this->data;
  $canLab = auth()->user()->can('lab.view');
  $canImaging = auth()->user()->can('radiology.view');
@endphp
<div class="tb-stack">
  <div class="tb-grid-2">
    <div class="tb-card">
      <div class="tb-card-header"><span class="tb-card-title">Diagnoses</span></div>
      @if($c['diagnoses'] === [])
        <div class="tb-card-body"><x-ui.empty icon="fa-notes-medical" compact message="No diagnosis has been recorded." /></div>
      @else
        <div class="tb-table-wrap">
          <table class="tb-table">
            <caption class="sr-only">Diagnosis history</caption>
            <thead><tr><th scope="col" class="tb-serial">#</th><th scope="col">Date</th><th scope="col">Diagnosis</th><th scope="col">Visit</th></tr></thead>
            <tbody>
              @foreach($c['diagnoses'] as $x)
                <tr wire:key="cd-{{ $x['visit_uuid'] }}">
                  <td class="tb-serial mono">{{ $loop->iteration }}</td>
                  <td class="tb-nowrap">{{ $d($x['date']) }}</td>
                  <td>{{ $x['diagnosis'] }}</td>
                  <td class="mono rowlink"><x-ui.link :href="route('admin.visits.show', $x['visit_uuid'])">{{ $x['visit_no'] }}</x-ui.link></td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </div>

    <div class="tb-card">
      <div class="tb-card-header"><span class="tb-card-title">Current medication</span></div>
      @if($c['medication'] === [])
        <div class="tb-card-body"><x-ui.empty icon="fa-pills" compact message="Not on any medication." /></div>
      @else
        <div class="tb-table-wrap">
          <table class="tb-table">
            <caption class="sr-only">Medication the patient is on now</caption>
            <thead><tr><th scope="col" class="tb-serial">#</th><th scope="col">Drug</th><th scope="col">Dose</th><th scope="col">Until</th></tr></thead>
            <tbody>
              @foreach($c['medication'] as $m)
                <tr wire:key="cm-{{ $loop->index }}">
                  <td class="tb-serial mono">{{ $loop->iteration }}</td>
                  <td><b>{{ $m['drug'] }}</b>@if($m['instructions'])<div class="muted tb-xs">{{ $m['instructions'] }}</div>@endif</td>
                  <td>{{ $m['dosage'] ?? '—' }}</td>
                  <td class="tb-nowrap">{{ $d($m['ends_on']) }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </div>
  </div>

  <div class="tb-card">
    <div class="tb-card-header"><span class="tb-card-title">Prescriptions</span></div>
    @if($c['prescriptions'] === [])
      <div class="tb-card-body"><x-ui.empty icon="fa-prescription" compact message="Nothing has been prescribed." /></div>
    @else
      <div class="tb-table-wrap">
        <table class="tb-table">
          <caption class="sr-only">Prescriptions written for this patient</caption>
          <thead><tr><th scope="col" class="tb-serial">#</th><th scope="col">Date</th><th scope="col">Drugs</th><th scope="col">Prescribed by</th><th scope="col">Visit</th></tr></thead>
          <tbody>
            @foreach($c['prescriptions'] as $p)
              <tr wire:key="cp-{{ $loop->index }}">
                <td class="tb-serial mono">{{ $loop->iteration }}</td>
                <td class="tb-nowrap">{{ $d($p['date']) }}</td>
                <td>{{ collect($p['lines'])->map(fn ($l) => $l['drug'].($l['dosage'] ? ' '.$l['dosage'] : '').' × '.$l['days'].'d')->implode(', ') ?: '—' }}</td>
                <td>{{ $p['prescriber'] ?? '—' }}</td>
                <td class="mono rowlink">@if($p['visit_uuid'])<x-ui.link :href="route('admin.visits.show', $p['visit_uuid'])">{{ $p['visit_no'] }}</x-ui.link>@else — @endif</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>

  <div class="tb-card">
    <div class="tb-card-header"><span class="tb-card-title">Vitals</span><span class="muted tb-small">Latest first - read down for the trend</span></div>
    @if($c['vitals'] === [])
      <div class="tb-card-body"><x-ui.empty icon="fa-heart-pulse" compact message="No vitals have been recorded." /></div>
    @else
      <div class="tb-table-wrap">
        <table class="tb-table">
          <caption class="sr-only">Vitals on past visits</caption>
          <thead><tr><th scope="col" class="tb-serial">#</th><th scope="col">Taken</th><th scope="col">Temp °C</th><th scope="col">BP</th><th scope="col">Pulse</th><th scope="col">Resp.</th><th scope="col">SpO₂ %</th><th scope="col">Weight kg</th><th scope="col">BMI</th></tr></thead>
          <tbody>
            @foreach($c['vitals'] as $v)
              <tr wire:key="cv-{{ $v['visit_uuid'] }}">
                <td class="tb-serial mono">{{ $loop->iteration }}</td>
                <td class="tb-nowrap">{{ $d($v['recorded_at'], 'd M Y H:i') }}</td>
                <td>{{ $v['temperature'] ?? '—' }}</td><td>{{ $v['blood_pressure'] ?? '—' }}</td><td>{{ $v['pulse'] ?? '—' }}</td>
                <td>{{ $v['respiratory_rate'] ?? '—' }}</td><td>{{ $v['spo2'] ?? '—' }}</td><td>{{ $v['weight'] ?? '—' }}</td><td>{{ $v['bmi'] ?? '—' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>

  @if(in_array('lab', $this->sections, true))
    <div class="tb-card">
      <div class="tb-card-header"><span class="tb-card-title">Lab orders and results</span></div>
      @if($c['lab'] === [])
        <div class="tb-card-body"><x-ui.empty icon="fa-flask" compact message="No lab tests have been ordered." /></div>
      @else
        @foreach($c['lab'] as $o)
          <div class="tb-card-body" wire:key="cl-{{ $o['uuid'] }}">
            <div class="tb-flex">
              <b>{{ $d($o['date']) }}</b>
              <x-ui.badge :tone="$o['status_badge']">{{ $o['status'] }}</x-ui.badge>
              @if($o['abnormal'])<x-ui.badge tone="danger">{{ $o['abnormal'] }} abnormal</x-ui.badge>@endif
              @if($canLab)<x-ui.link :href="route('admin.lab-orders.show', $o['uuid'])" class="tb-small">Open order</x-ui.link>@endif
            </div>
            <div class="tb-table-wrap tb-mt-2">
              <table class="tb-table">
                <caption class="sr-only">Results of the lab order of {{ $d($o['date']) }}</caption>
                <thead><tr><th scope="col">Test</th><th scope="col">Result</th><th scope="col">Range</th><th scope="col">Flag</th></tr></thead>
                <tbody>
                  @foreach($o['tests'] as $t)
                    <tr>
                      <td>{{ $t['name'] }}</td>
                      <td class="mono">{{ $t['value'] !== null && $t['value'] !== '' ? $t['value'].' '.$t['unit'] : 'Awaiting result' }}</td>
                      <td class="mono muted">{{ $t['range'] ?? '—' }}</td>
                      <td>@if($t['flag'])<x-ui.badge :tone="$t['flag_badge']">{{ $t['flag_label'] }}</x-ui.badge>@endif</td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          </div>
        @endforeach
      @endif
    </div>
  @endif

  @if(in_array('imaging', $this->sections, true))
    <div class="tb-card">
      <div class="tb-card-header"><span class="tb-card-title">Imaging reports</span></div>
      @if($c['imaging'] === [])
        <div class="tb-card-body"><x-ui.empty icon="fa-x-ray" compact message="No imaging has been ordered." /></div>
      @else
        @foreach($c['imaging'] as $o)
          <div class="tb-card-body" wire:key="ci-{{ $o['uuid'] }}">
            <div class="tb-flex">
              <b>{{ implode(', ', $o['studies']) ?: 'Imaging' }}</b>
              <span class="muted tb-small">{{ $d($o['date']) }}</span>
              <x-ui.badge :tone="$o['status_badge']">{{ $o['status'] }}</x-ui.badge>
              @if($canImaging)<x-ui.link :href="route('admin.radiology-orders.show', $o['uuid'])" class="tb-small">Open order</x-ui.link>@endif
            </div>
            @if($o['impression'] || $o['findings'])
              <div class="tb-small tb-mt-2">@if($o['impression'])<b>Impression:</b> {{ $o['impression'] }}<br>@endif @if($o['findings'])<span class="muted">{{ $o['findings'] }}</span>@endif</div>
            @else
              <div class="muted tb-small tb-mt-2">No report yet.</div>
            @endif
          </div>
        @endforeach
      @endif
    </div>
  @endif

  @if(in_array('admissions', $this->sections, true))
    <div class="tb-card">
      <div class="tb-card-header"><span class="tb-card-title">Admissions</span></div>
      @if($c['admissions'] === [])
        <div class="tb-card-body"><x-ui.empty icon="fa-bed" compact message="Never admitted." /></div>
      @else
        <div class="tb-table-wrap">
          <table class="tb-table">
            <caption class="sr-only">Admissions</caption>
            <thead><tr><th scope="col" class="tb-serial">#</th><th scope="col">Ward / bed</th><th scope="col">Admitted</th><th scope="col">Discharged</th><th scope="col">Nights</th><th scope="col">Status</th><th scope="col">Reason</th></tr></thead>
            <tbody>
              @foreach($c['admissions'] as $a)
                <tr wire:key="ca-{{ $a['uuid'] }}">
                  <td class="tb-serial mono">{{ $loop->iteration }}</td>
                  <td class="rowlink"><x-ui.link :href="route('admin.admissions.show', $a['uuid'])">{{ $a['ward'] ?? 'Ward' }} · {{ $a['bed'] ?? '—' }}</x-ui.link></td>
                  <td class="tb-nowrap">{{ $d($a['admitted_at']) }}</td>
                  <td class="tb-nowrap">{{ $d($a['discharged_at']) }}</td>
                  <td>{{ $a['nights'] }}</td>
                  <td><x-ui.badge :tone="$a['status_badge']">{{ $a['status'] }}</x-ui.badge></td>
                  <td>{{ \Illuminate\Support\Str::limit($a['reason'] ?? '', 60) ?: '—' }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </div>
  @endif
</div>
