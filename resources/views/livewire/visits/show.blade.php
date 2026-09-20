<div>
  <x-ui.page-header :title="$visit->patient?->full_name ?? $visit->visit_no"
                    :crumbs="['Visits' => route('admin.visits.index'), $visit->visit_no => null]">
    <x-slot:actions>
      <x-ui.badge :tone="$visit->stateBadge()">{{ $visit->stateLabel() }}</x-ui.badge>
    </x-slot:actions>
  </x-ui.page-header>

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

      <livewire:visits.panels.vitals :visit-id="$visit->id" :key="'vitals-'.$visit->id" />
    </div>

    {{-- ── Workspace panels (each #[Lazy]) ──────────────────── --}}
    <div class="tb-stack">
      <livewire:visits.panels.clinical :visit-id="$visit->id" :key="'clinical-'.$visit->id" />

      @canany(['billing.view', 'billing.manage'])
        <livewire:visits.panels.charges :visit-id="$visit->id" :key="'charges-'.$visit->id" />
      @endcanany

      @can('lab.order')
        <livewire:visits.panels.lab-orders :visit-id="$visit->id" :key="'labs-'.$visit->id" />
      @endcan

      @can('radiology.order')
        <livewire:visits.panels.radiology-orders :visit-id="$visit->id" :key="'rads-'.$visit->id" />
      @endcan

      @can('pharmacy.dispense')
        <livewire:visits.panels.dispense :visit-id="$visit->id" :key="'disp-'.$visit->id" />
      @endcan

      <livewire:visits.panels.prescriptions :visit-id="$visit->id" :key="'rx-'.$visit->id" />

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
