@php
  $f = $this->figures;
  $money = fn ($v) => \App\Support\HospitalSettings::money($v);
  $d = fn ($iso, $fmt = 'd M Y') => $iso ? \Illuminate\Support\Carbon::parse($iso)->format($fmt) : null;
  $age = \App\Support\PatientBrief::age($patient);
@endphp
<div>
  {{-- ── 1. Header band ─────────────────────────────────────── --}}
  <x-ui.page-header :title="$patient->full_name"
                    :crumbs="['Patients' => route('admin.patients.index'), $patient->patient_no => null]">
    <x-slot:subtitle>
      <div class="tb-flex tb-small tb-mt-2">
        <span class="mono tb-primary">{{ $patient->patient_no }}</span>
        @if($age || $patient->sex)<span class="muted">{{ collect([$age, $patient->sex?->label()])->filter()->implode(' · ') }}</span>@endif
        <x-ui.badge :tone="$patient->status->badge()">{{ $patient->status->label() }}</x-ui.badge>
      </div>
    </x-slot:subtitle>
    <x-slot:actions>
      @if(! empty($f['open_visit']))
        <x-ui.link :href="route('admin.visits.show', $f['open_visit']['uuid'])" class="btn-tb btn-tb-primary">
          <i class="fas fa-stethoscope" aria-hidden="true"></i> Open visit {{ $f['open_visit']['visit_no'] }}
        </x-ui.link>
      @elseif($canNewVisit)
        <x-ui.link :href="route('admin.visits.index', ['for' => $patient->uuid])" class="btn-tb btn-tb-primary">
          <i class="fas fa-stethoscope" aria-hidden="true"></i> New visit
        </x-ui.link>
      @endif
      @if($canBook)
        <x-ui.link :href="route('admin.appointments.index', ['for' => $patient->uuid])" class="btn-tb">
          <i class="fas fa-calendar-plus" aria-hidden="true"></i> Book appointment
        </x-ui.link>
      @endif
      @if($canStatement)
        <x-ui.link :href="route('admin.patients.statement', $patient)" :navigate="false"
                   target="_blank" rel="noopener" class="btn-tb">
          <i class="fas fa-file-invoice" aria-hidden="true"></i> Statement
        </x-ui.link>
      @endif
      <x-ui.link :href="route('admin.patients.id-card', $patient)" :navigate="false"
                 target="_blank" rel="noopener" class="btn-tb">
        <i class="fas fa-id-card" aria-hidden="true"></i> ID card (PDF)
      </x-ui.link>
      @can('update', $patient)
        <x-ui.link :href="route('admin.patients.edit', $patient)" class="btn-tb">
          <i class="fas fa-pen" aria-hidden="true"></i> Edit
        </x-ui.link>
      @endcan
      @can('delete', $patient)
        <button type="button" class="btn-tb btn-tb-danger" wire:click="archive" wire:confirm="Archive this patient?"
                wire:loading.attr="disabled" wire:target="archive">
          <i class="fas fa-box-archive" aria-hidden="true"></i> Archive
        </button>
      @endcan
    </x-slot:actions>
  </x-ui.page-header>

  {{-- ── 2. Alert strip: only what applies ──────────────────── --}}
  @if($this->alerts !== [])
    <div class="tb-stack tb-stack-tight tb-mb-4" role="region" aria-label="Alerts for this patient">
      @foreach($this->alerts as $alert)
        @php
          $cls = match ($alert['tone']) { 'danger' => 'tb-alert-danger', 'warn' => 'tb-alert-warning', default => '' };
          $icon = match ($alert['key']) {
            'allergies' => 'fa-triangle-exclamation', 'chronic' => 'fa-heart-pulse', 'owes' => 'fa-coins',
            'no_consent' => 'fa-file-signature', 'status' => 'fa-user-slash', 'admitted' => 'fa-bed', default => 'fa-shield-heart',
          };
        @endphp
        <div class="tb-alert {{ $cls }}" role="status" wire:key="alert-{{ $alert['key'] }}">
          <i class="fas {{ $icon }}" aria-hidden="true"></i>
          <div>
            @switch($alert['key'])
              @case('allergies')
                <b>Allergies</b>
                @foreach($alert['items'] as $item)<x-ui.badge tone="warn">{{ $item }}</x-ui.badge>@endforeach
                @break
              @case('chronic')
                <b>Chronic conditions</b>
                @foreach($alert['items'] as $item)<x-ui.badge tone="info">{{ $item }}</x-ui.badge>@endforeach
                @break
              @case('owes')
                <b>Owes {{ $money($alert['amount']) }}</b>
                @if($alert['oldest_unpaid_days'] !== null) - oldest unpaid {{ $alert['oldest_unpaid_days'] }} day{{ $alert['oldest_unpaid_days'] === 1 ? '' : 's' }}@endif
                <button type="button" class="tb-rowbtn" wire:click="setTab('billing')">See billing</button>
                @break
              @case('no_consent')
                <b>No consent recorded</b>
                <span class="muted">Record it by editing the patient.</span>
                @break
              @case('status')
                <b>{{ $alert['label'] }}</b>
                <span class="muted">{{ $alert['status'] === 'deceased' ? 'This patient is recorded as deceased.' : 'This patient is marked inactive.' }}</span>
                @break
              @case('admitted')
                <b>Currently admitted</b> in {{ $alert['ward'] ?? 'a ward' }}@if($alert['bed']), bed {{ $alert['bed'] }}@endif since {{ $d($alert['since']) }}
                @break
              @case('insurance')
                <b>Insurance</b>
                {{ collect([$alert['provider'], $alert['member_no']])->filter()->implode(' · ') ?: 'on file' }}
                @if($alert['claims_awaiting'])
                  - {{ $alert['claims_awaiting'] }} claim{{ $alert['claims_awaiting'] === 1 ? '' : 's' }} awaiting ({{ $money($alert['claims_amount']) }})
                @endif
                @break
            @endswitch
          </div>
        </div>
      @endforeach
    </div>
  @endif

  {{-- ── 3. Figures ─────────────────────────────────────────── --}}
  @php($hasFigures = isset($f['outstanding']) || isset($f['visits_count']) || isset($f['next_appointment']) || ! empty($f['card_balance']) || (isset($f['cards_count']) && $f['cards_count'] > 0))
  @if($hasFigures)
    <div class="tb-stats-grid tb-mb-4" role="group" aria-label="Figures for this patient">
      @isset($f['outstanding'])
        <x-dash.stat icon="fa-coins" label="Outstanding" :value="$money($f['outstanding'])"
                     :tone="bccomp($f['outstanding'], '0', 2) > 0 ? 'bad' : 'ok'"
                     :sub="$f['outstanding_count'] ? $f['outstanding_count'].' unpaid invoice'.($f['outstanding_count'] === 1 ? '' : 's') : 'Nothing owed'" />
        <x-dash.stat icon="fa-file-invoice-dollar" label="Billed" :value="$money($f['billed_total'])" sub="All invoices" />
        <x-dash.stat icon="fa-money-bill-wave" label="Paid" :value="$money($f['paid_total'])" sub="All payments" />
      @endisset
      @if(isset($f['cards_count']) && $f['cards_count'] > 0)
        <x-dash.stat icon="fa-wallet" label="Card balance" :value="$money($f['card_balance'])"
                     :tone="bccomp($f['card_balance'], '0', 2) < 0 ? 'bad' : ''" :sub="$f['cards_count'].' active card'.($f['cards_count'] === 1 ? '' : 's')" />
      @endif
      @isset($f['visits_count'])
        <x-dash.stat icon="fa-stethoscope" label="Visits" :value="number_format($f['visits_count'])"
                     :sub="$f['last_visit_at'] ? 'Last '.$d($f['last_visit_at']) : 'None yet'" />
      @endisset
      @if(array_key_exists('next_appointment', $f))
        <x-dash.stat icon="fa-calendar-check" label="Next appointment"
                     :value="$f['next_appointment'] ? $d($f['next_appointment']['scheduled_at'], 'd M') : 'Not booked'"
                     :sub="$f['next_appointment'] ? $d($f['next_appointment']['scheduled_at'], 'H:i').($f['next_appointment']['doctor'] ? ' · '.$f['next_appointment']['doctor'] : '') : 'Nothing booked'" />
      @endif
    </div>
  @endif

  {{-- ── 4. Tabs (state in ?tab=, each mounted on first open) ─ --}}
  <div class="tb-tabs tb-tabs-scroll" role="tablist" aria-label="Patient record">
    @foreach($this->tabs as $key => [$label, $icon])
      <button type="button" role="tab" id="ptab-{{ $key }}" aria-controls="ppanel-{{ $key }}" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
              class="tb-tab {{ $tab === $key ? 'on' : '' }}" wire:click="setTab('{{ $key }}')" wire:key="ptab-{{ $key }}">
        <i class="fas {{ $icon }}" aria-hidden="true"></i> {{ $label }}
        @if($key === 'visits' && isset($f['visits_count']) && $f['visits_count'] > 0)<span class="n">{{ $f['visits_count'] }}</span>@endif
        @if($key === 'billing' && ! empty($f['outstanding_count']))<span class="n">{{ $f['outstanding_count'] }}</span>@endif
      </button>
    @endforeach
  </div>

  @foreach($opened as $key)
    @continue(! array_key_exists($key, $this->tabs))
    <div id="ppanel-{{ $key }}" role="tabpanel" aria-labelledby="ptab-{{ $key }}" wire:key="ppanel-{{ $key }}" @if($tab !== $key) hidden @endif>
      @switch($key)
        @case('overview')
          <livewire:patients.tabs.overview :patient-id="$patient->id" :key="'t-overview-'.$patient->id" />
          @break
        @case('visits')
          <livewire:patients.tabs.visits :patient-id="$patient->id" :key="'t-visits-'.$patient->id" />
          @break
        @case('billing')
          <livewire:patients.tabs.billing :patient-id="$patient->id" :key="'t-billing-'.$patient->id" />
          @break
        @case('clinical')
          <livewire:patients.tabs.clinical :patient-id="$patient->id" :key="'t-clinical-'.$patient->id" />
          @break
        @case('appointments')
          <livewire:patients.tabs.appointments :patient-id="$patient->id" :key="'t-appointments-'.$patient->id" />
          @break
        @case('dental')
          <livewire:patients.tabs.dental :patient-id="$patient->id" :key="'t-dental-'.$patient->id" />
          @break
        @case('access')
          <livewire:patients.tabs.access :patient-id="$patient->id" :key="'t-access-'.$patient->id" />
          @break
        {{-- Maternity and child health (docs/mother-child.md). --}}
        @case('pregnancy')
          <livewire:patients.tabs.pregnancy :patient-id="$patient->id" :key="'t-pregnancy-'.$patient->id" />
          @break
        @case('immunisation')
          <livewire:patients.tabs.immunisation :patient-id="$patient->id" :key="'t-immunisation-'.$patient->id" />
          @break
        @case('growth')
          <livewire:patients.tabs.growth :patient-id="$patient->id" :key="'t-growth-'.$patient->id" />
          @break

        {{-- Records: the working panels, unchanged in behaviour. --}}
        @case('records')
          <div class="tb-stack">
            @can('manageCards', $patient)
              <livewire:patients.panels.cards :patient-id="$patient->id" :key="'cards-'.$patient->id" />
            @endcan

            <livewire:patients.panels.documents :patient-id="$patient->id" :key="'docs-'.$patient->id" />

            <livewire:patients.panels.dependents :patient-id="$patient->id" :key="'deps-'.$patient->id" />

            @canany(['insurance.view', 'insurance.manage'])
              <livewire:patients.panels.insurances :patient-id="$patient->id" :key="'ins-'.$patient->id" />
            @endcanany

            @canany(['treatments.view', 'treatments.manage'])
              <livewire:patients.panels.treatments :patient-id="$patient->id" :key="'treat-'.$patient->id" />
            @endcanany
          </div>
          @break

        {{-- Details: the facts held on the patient row. --}}
        @case('details')
        <div class="tb-grid-2">
          <div class="tb-stack">
        <div class="tb-card">
          <div class="tb-card-header"><span class="tb-card-title">Identity</span></div>
          <div class="tb-table-wrap">
            <table class="tb-table">
              <caption class="sr-only">Identity</caption>
              <tbody>
                <tr><th scope="row">Patient no.</th><td class="mono">{{ $patient->patient_no }}</td></tr>
                <tr><th scope="row">Status</th><td><x-ui.badge :tone="$patient->status->badge()">{{ $patient->status->label() }}</x-ui.badge></td></tr>
                <tr><th scope="row">Sex</th><td>{{ $patient->sex?->label() ?? '—' }}</td></tr>
                <tr><th scope="row">Age</th><td>{{ $patient->age() !== null ? $patient->age().' yrs' : '—' }}</td></tr>
                <tr><th scope="row">Date of birth</th><td>{{ $patient->dob?->format('d M Y') ?? '—' }}</td></tr>
                <tr><th scope="row">Blood type</th><td>{{ $patient->blood_type ?? '—' }}</td></tr>
                <tr><th scope="row">District</th><td>{{ $patient->district?->name ?? '—' }}</td></tr>
                <tr><th scope="row">Registered</th><td>{{ $patient->created_at->format('d M Y') }}@if($patient->registeredBy) · {{ $patient->registeredBy->name }}@endif</td></tr>
              </tbody>
            </table>
          </div>
        </div>
        <div class="tb-card">
          <div class="tb-card-header"><span class="tb-card-title">Contact</span></div>
          <div class="tb-table-wrap">
            <table class="tb-table">
              <caption class="sr-only">Contact</caption>
              <tbody>
                <tr><th scope="row">Phone</th><td>{{ $patient->phone_1 ?? '—' }}</td></tr>
                <tr><th scope="row">Alt phone</th><td>{{ $patient->phone_2 ?? '—' }}</td></tr>
                <tr><th scope="row">Email</th><td>{{ $patient->email ?? '—' }}</td></tr>
                <tr><th scope="row">Address</th><td>{{ $patient->address ?? '—' }}</td></tr>
                <tr><th scope="row">Home address</th><td>{{ $patient->home_address ?? '—' }}</td></tr>
              </tbody>
            </table>
          </div>
        </div>
        <div class="tb-card">
          <div class="tb-card-header"><span class="tb-card-title">Medical</span></div>
          <div class="tb-card-body">
            <div class="tb-label">Allergies</div>
            <div class="tb-flex">
              @forelse(($patient->allergies ?? []) as $allergy)
                <x-ui.badge tone="warn">{{ $allergy }}</x-ui.badge>
              @empty
                <span class="muted tb-small">None recorded</span>
              @endforelse
            </div>
            <div class="tb-label tb-mt-4">Chronic conditions</div>
            <div class="tb-flex">
              @forelse(($patient->chronic_conditions ?? []) as $condition)
                <x-ui.badge tone="info">{{ $condition }}</x-ui.badge>
              @empty
                <span class="muted tb-small">None recorded</span>
              @endforelse
            </div>
          </div>
        </div>
          </div>
          <div class="tb-stack">
        <div class="tb-card">
          <div class="tb-card-header"><span class="tb-card-title">Family &amp; emergency</span></div>
          <div class="tb-table-wrap">
            <table class="tb-table">
              <caption class="sr-only">Family and emergency contacts</caption>
              <tbody>
                <tr><th scope="row">Spouse</th><td>{{ $patient->spouse_name ?? '—' }}</td></tr>
                <tr><th scope="row">Father</th><td>{{ $patient->father_name ?? '—' }}</td></tr>
                <tr><th scope="row">Mother</th><td>{{ $patient->mother_name ?? '—' }}</td></tr>
                <tr><th scope="row">Emergency contact</th>
                  <td>{{ $patient->emergency_contact_name ?? '—' }}@if($patient->emergency_contact_phone) · {{ $patient->emergency_contact_phone }}@endif</td></tr>
              </tbody>
            </table>
          </div>
        </div>
        <div class="tb-card">
          <div class="tb-card-header"><span class="tb-card-title">Insurance on file</span></div>
          <div class="tb-table-wrap">
            <table class="tb-table">
              <caption class="sr-only">Insurance recorded on the patient record</caption>
              <tbody>
                <tr><th scope="row">Provider</th><td>{{ $patient->insurance_provider ?? '—' }}</td></tr>
                <tr><th scope="row">Member no.</th><td class="mono">{{ $patient->insurance_member_no ?? '—' }}</td></tr>
              </tbody>
            </table>
          </div>
        </div>
        <div class="tb-card">
          <div class="tb-card-header"><span class="tb-card-title">Consent</span></div>
          <div class="tb-card-body">
            @if($patient->consent_given)
              <x-ui.badge tone="success">Consent given</x-ui.badge>
              <div class="muted tb-xs tb-mt-2">{{ $patient->consent_at?->format('d M Y H:i') ?? 'Date not recorded' }}</div>
            @else
              <x-ui.badge tone="danger">No consent recorded</x-ui.badge>
            @endif
            <div class="tb-label tb-mt-3">Text messages</div>
            @forelse($this->consents as $c)
              <div class="tb-small" wire:key="consent-{{ $c->id }}">
                <x-ui.badge :tone="$c->granted ? 'success' : 'neutral'">{{ $c->granted ? 'Yes' : 'No' }}</x-ui.badge>
                {{ $c->purpose->label() }}, by {{ $c->channel->label() }}
                <span class="muted tb-xs">· {{ $c->recorded_at->format('d M Y') }}{{ $c->method === 'reply' ? ', replied STOP' : '' }}{{ $c->recordedBy ? ', '.$c->recordedBy->name : '' }}</span>
              </div>
            @empty
              <div class="muted tb-small">Not asked yet. Edit the patient to record their answer.</div>
            @endforelse
          </div>
        </div>
        @if($patient->notes)
          <div class="tb-card">
            <div class="tb-card-header"><span class="tb-card-title">Notes</span></div>
            <div class="tb-card-body muted tb-small">{{ $patient->notes }}</div>
          </div>
        @endif
          </div>
        </div>
          @break
      @endswitch
    </div>
  @endforeach
</div>
