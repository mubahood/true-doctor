<div>
  <x-ui.page-header :title="$visit->patient?->full_name ?? $visit->visit_no"
                    :crumbs="['Visits' => route('admin.visits.index'), $visit->visit_no => null]">
    <x-slot:actions>
      @if($printer = app(\App\Support\HospitalSettings::class)->receiptPrinter())
        <a href="{{ route('admin.visits.ticket', [$visit, 'w' => $printer]) }}" target="_blank" rel="noopener" class="btn-tb btn-tb-sm"><i class="fas fa-ticket" aria-hidden="true"></i> Queue ticket</a>
      @endif
      @can('delete', $visit)
        <button type="button" class="btn-tb btn-tb-sm btn-tb-ghost"
                wire:click="$dispatch('visit-delete', { visitId: {{ $visit->id }} })">
          <i class="fas fa-trash" aria-hidden="true"></i> Delete visit
        </button>
      @endcan
      <x-ui.badge :tone="$visit->stateBadge()">{{ $visit->stateLabel() }}</x-ui.badge>
    </x-slot:actions>
  </x-ui.page-header>

  @can('delete', $visit)
    <livewire:visits.delete-visit :leave="true" />
  @endcan

  <x-clinical.alerts :patient="$visit->patient" />

  {{-- ── Where it is, and what is holding it ──────────────────────────
       Nobody picks a stage: the gate decides, and when it is shut it says
       what is shutting it, in figures (docs/visits.md). --}}
  @can('manage', $visit)
    @if($visit->isOpen())
      @php($gate = $this->gate)
      <div class="tb-card tb-mb-5">
        <div class="tb-card-body tb-gate">
          <div class="tb-gate-now">
            <span class="k">Now at</span>
            <x-ui.badge :tone="$visit->stateBadge()">{{ $visit->stateLabel() }}</x-ui.badge>
          </div>

          <div class="tb-gate-next">
            @if($gate['automatic'])
              <span class="tb-gate-auto">
                <i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i>
                {{ $gate['ready']
                    ? 'Settled — completing…'
                    : 'Completes itself once the bill is paid in full.' }}
              </span>
              @if($gate['blocker'])<span class="tb-gate-block">{{ $gate['blocker'] }}</span>@endif
            @elseif($gate['ready'])
              <button type="button" class="btn-tb btn-tb-sm btn-tb-primary"
                      wire:click="advance" wire:loading.attr="disabled" wire:target="advance">
                <span wire:loading.remove wire:target="advance">
                  {{ $gate['label'] }} <i class="fas fa-arrow-right" aria-hidden="true"></i>
                </span>
                <span wire:loading wire:target="advance">Moving…</span>
              </button>
            @elseif($gate['blocker'])
              <span class="tb-gate-block">
                <i class="fas fa-lock" aria-hidden="true"></i> {{ $gate['blocker'] }}
              </span>
            @endif
          </div>

          <div class="tb-gate-off">
            <label class="sr-only" for="cancel-note">Why the visit is being called off</label>
            <input id="cancel-note" type="text" class="tb-input" wire:model="cancelNote"
                   placeholder="Reason, if cancelling" maxlength="255">
            <button type="button" class="btn-tb btn-tb-sm btn-tb-ghost tb-vs-cancelbtn"
                    wire:click="cancelVisit" wire:loading.attr="disabled" wire:target="cancelVisit">
              <i class="fas fa-ban" aria-hidden="true"></i> Cancel visit
            </button>
          </div>

          @error('cancelNote')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
        </div>
      </div>
    @endif
  @endcan

  <div class="tb-detail-cols">
    {{-- ── Summary column ───────────────────────────────────── --}}
    <div class="tb-stack">
      <div class="tb-card">
        <div class="tb-table-wrap">
          <table class="tb-table">
            <caption class="sr-only">Visit summary</caption>
            <tbody>
              <tr><th scope="row">Visit</th><td class="mono">{{ $visit->visit_no }}</td></tr>
              <tr>
                <th scope="row">Patient</th>
                <td>
                  @if($visit->patient)
                    <x-ui.link :href="route('admin.patients.show', $visit->patient)">{{ $visit->patient->full_name }}</x-ui.link>
                    <div class="mono muted tb-xs">{{ $visit->patient->patient_no }}</div>
                  @else
                    —
                  @endif
                </td>
              </tr>
              <tr><th scope="row">Doctor</th><td>{{ $visit->doctor?->name ?? '—' }}</td></tr>
              <tr><th scope="row">Department</th><td>{{ $visit->department?->name ?? '—' }}</td></tr>
              <tr><th scope="row">Opened</th><td>{{ $visit->created_at->format('d M Y H:i') }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      {{-- Vitals: the clinical team and the desk, which takes them at intake (VisitPolicy::viewVitals). --}}
      @can('viewVitals', $visit)
        <livewire:visits.panels.vitals :visit-id="$visit->id" :key="'vitals-'.$visit->id" />
      @endcan

      {{-- Book the review from here (Recalls and reviews due, docs/follow-up.md). A dental
           visit books its review from the case sheet's plan instead. --}}
      @if(auth()->user()?->can('appointments.manage') && ! ($visit->patient && \App\Services\DentalCaseSheetService::appliesTo($visit, app(\App\Support\HospitalSettings::class)->hospital())))
        <livewire:visits.panels.review :visit-id="$visit->id" :key="'review-'.$visit->id" />
      @endif
    </div>

    {{-- ── Workspace panels (each #[Lazy]) ──────────────────── --}}
    <div class="tb-stack">
      {{-- The clinical record is for the clinical team (Staff and access, docs/rbac.md):
           the desk and the cashier see the visit, its reason and its bill. A dental
           visit gets the dental case sheet in place of the general note (docs/dental.md). --}}
      @php($dentalHospital = app(\App\Support\HospitalSettings::class)->hospital())
      @if(! auth()->user()?->can('viewClinical', $visit))
        <x-ui.no-access title="Clinical notes" icon="fa-notes-medical" />
      @elseif($visit->patient && \App\Services\DentalNotesService::appliesTo($visit, $dentalHospital))
        {{-- Simple notes: History and Oral examination as plain text, a dental clinic's
             default (Settings, Your practice; docs/dental.md, "Simple notes"). --}}
        @include('livewire.visits.partials.dental-notes', ['visit' => $visit])
      @elseif($visit->patient && \App\Services\DentalCaseSheetService::appliesTo($visit, $dentalHospital))
        @include('livewire.visits.partials.dental-case-sheet', ['visit' => $visit])
      @else
      <livewire:visits.panels.clinical :visit-id="$visit->id" :key="'clinical-'.$visit->id" />
      <livewire:visits.panels.diagnoses :visit-id="$visit->id" :key="'diagnoses-'.$visit->id" />

      {{-- A dental clinic charts the teeth and bills procedures on the visit (docs/dental.md).
           Elsewhere the chart waits behind a press (DentalService::chartOnVisit). --}}
      @if($visit->patient && \App\Services\DentalService::enabledFor($dentalHospital))
        @canany(['treatments.view', 'visits.diagnose'])
          @if($dentalOpen || \App\Services\DentalService::chartOnVisit($visit, $dentalHospital))
            <livewire:patients.tabs.dental :patient-id="$visit->patient_id" :visit-id="$visit->id" :key="'dental-'.$visit->id" />
          @else
            <div class="tb-card">
              <div class="tb-card-header">
                <span class="tb-card-title"><i class="fas fa-tooth" aria-hidden="true"></i> Dental chart</span>
                <button type="button" class="btn-tb btn-tb-sm" wire:click="$set('dentalOpen', true)" wire:loading.attr="disabled" wire:target="dentalOpen">Open the chart</button>
              </div>
            </div>
          @endif
        @endcanany
      @endif
      @endif

      @can('viewBill', $visit)
        <livewire:visits.panels.charges :visit-id="$visit->id" :key="'charges-'.$visit->id" />
      @endcan

      @can('lab.order')
        <livewire:visits.panels.lab-orders :visit-id="$visit->id" :key="'labs-'.$visit->id" />
      @endcan

      @can('radiology.order')
        <livewire:visits.panels.radiology-orders :visit-id="$visit->id" :key="'rads-'.$visit->id" />
      @endcan

      @can('pharmacy.dispense')
        <livewire:visits.panels.dispense :visit-id="$visit->id" :key="'disp-'.$visit->id" />
      @endcan

      @can('viewClinical', $visit)
        {{-- A dental clinic writes the medicine as a dosage: picked from stock, the dose,
             how often, for how many days (docs/pharmacy.md, "Dosage"). --}}
        @if(\App\Support\Practice\VisitForm::current()->writesDosage())
          <livewire:visits.panels.dosage :visit-id="$visit->id" :key="'dosage-'.$visit->id" />
        @else
          <livewire:visits.panels.prescriptions :visit-id="$visit->id" :key="'rx-'.$visit->id" />
        @endif
      @endcan

      <div class="tb-card">
        <div class="tb-card-header"><span class="tb-card-title">History</span></div>
        <div class="tb-table-wrap">
          <table class="tb-table">
            <caption class="sr-only">Status history</caption>
            <thead><tr><th>When</th><th>Change</th><th>Note</th><th>By</th></tr></thead>
            <tbody>
              @foreach($visit->history as $entry)
                <tr wire:key="hist-{{ $entry->id }}">
                  <td class="tb-nowrap">{{ $entry->created_at?->format('d M H:i') }}</td>
                  <td>{{ $entry->fromLabel() }} → {{ $entry->toLabel() }}</td>
                  <td class="muted">{{ $entry->note ?? '—' }}</td>
                  <td class="muted">{{ $entry->changedBy?->name ?? '—' }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
