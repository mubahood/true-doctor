<div>
  <x-ui.page-header :title="$admission->patient?->full_name ?? 'Admission'"
    :crumbs="['Admissions' => route('admin.admissions.index'), $admission->admitted_at->format('d M Y') => null]">
    <x-slot:subtitle>
      <div class="tb-flex tb-mt-2">
        <x-ui.badge :tone="$admission->status->badge()">{{ $admission->status->label() }}</x-ui.badge>
        @if($admission->patient)
          <x-ui.link :href="route('admin.patients.show', $admission->patient)" class="tb-small">{{ $admission->patient->full_name }}</x-ui.link>
        @endif
        <span class="muted tb-small">{{ $admission->bed?->ward?->name ?? '—' }} · {{ $admission->bed?->name ?? 'no bed' }}</span>
        <span class="muted tb-small">{{ $admission->nights() }} night(s)</span>
      </div>
    </x-slot:subtitle>
    <x-slot:actions>
      @can('manage', \App\Models\Admission::class)
        @if($admission->status->isActive())
          <button type="button" class="btn-tb" wire:click="openTransfer" wire:loading.attr="disabled" wire:target="openTransfer">
            <i class="fas fa-right-left" aria-hidden="true"></i> Transfer
          </button>
          <button type="button" class="btn-tb btn-tb-danger" wire:click="openDischarge" wire:loading.attr="disabled" wire:target="openDischarge">
            <i class="fas fa-door-open" aria-hidden="true"></i> Discharge
          </button>
        @endif
      @endcan
      @if($admission->status->isTerminal())
        {{-- Leaves the SPA on purpose: a generated document opens in its own
             tab rather than replacing the page (docs/documents.md). --}}
        <a href="{{ route('admin.admissions.summary', $admission) }}" target="_blank" rel="noopener" class="btn-tb">
          <i class="fas fa-file-pdf" aria-hidden="true"></i> Summary
        </a>
      @endif
    </x-slot:actions>
  </x-ui.page-header>

  <div class="tb-detail-cols">
    {{-- ── Summary column (read-only) ─────────────────────────── --}}
    <div class="tb-stack">
      <div class="tb-card">
        <div class="tb-table-wrap"><table class="tb-table">
          <caption class="sr-only">Admission summary</caption>
          <tbody>
            <tr><th scope="row">Patient</th><td>
              @if($admission->patient)
                <x-ui.link :href="route('admin.patients.show', $admission->patient)">{{ $admission->patient->full_name }}</x-ui.link>
              @else — @endif
            </td></tr>
            <tr><th scope="row">Patient no.</th><td class="mono">{{ $admission->patient?->patient_no ?? '—' }}</td></tr>
            <tr><th scope="row">Ward / bed</th><td>{{ $admission->bed?->ward?->name ?? '—' }} · {{ $admission->bed?->name ?? '—' }}</td></tr>
            <tr><th scope="row">Admitted</th><td>{{ $admission->admitted_at->format('d M Y H:i') }}</td></tr>
            <tr><th scope="row">Doctor</th><td>{{ $admission->admittingDoctor?->name ?? '—' }}</td></tr>
            <tr><th scope="row">Status</th><td><x-ui.badge :tone="$admission->status->badge()">{{ $admission->status->label() }}</x-ui.badge></td></tr>
            <tr><th scope="row">Nights</th><td>{{ $admission->nights() }}</td></tr>
            <tr><th scope="row">Reason</th><td>{{ $admission->reason ?? '—' }}</td></tr>
            @if($admission->visit)
              <tr><th scope="row">Visit</th><td>
                <x-ui.link :href="route('admin.visits.show', $admission->visit)">{{ $admission->visit->visit_no }}</x-ui.link>
              </td></tr>
            @endif
            @if($admission->status->isTerminal())
              <tr><th scope="row">Discharged</th><td>{{ $admission->discharged_at?->format('d M Y H:i') ?? '—' }}</td></tr>
              <tr><th scope="row">Bed charge</th><td><x-ui.money :amount="$admission->bed_charge_total" /></td></tr>
            @endif
          </tbody>
        </table></div>
      </div>

      <div class="tb-card">
        <div class="tb-card-header"><span class="tb-card-title">Bed transfers</span></div>
        <div class="tb-table-wrap"><table class="tb-table tb-small">
          <thead><tr><th>When</th><th>From</th><th>To</th><th>Reason</th></tr></thead>
          <tbody>
            @forelse($admission->transfers as $t)
              <tr wire:key="transfer-{{ $t->id }}">
                <td class="muted">{{ $t->created_at?->format('d M H:i') }}</td>
                <td>{{ $t->fromBed?->name ?? '—' }}</td>
                <td>{{ $t->toBed?->name ?? '—' }}</td>
                <td class="muted">{{ $t->reason ?? '—' }}</td>
              </tr>
            @empty
              <tr><td colspan="4"><x-ui.empty icon="fa-right-left" noun="transfers" /></td></tr>
            @endforelse
          </tbody>
        </table></div>
      </div>

      @if($admission->discharge_notes)
        <div class="tb-card">
          <div class="tb-card-header"><span class="tb-card-title">Discharge notes</span></div>
          <div class="tb-card-body muted tb-small">{{ $admission->discharge_notes }}</div>
        </div>
      @endif
    </div>

    {{-- ── Nursing panels (each #[Lazy]) ──────────────────────── --}}
    <div class="tb-stack">
      <livewire:admissions.panels.vital-rounds :admission-id="$admission->id" :key="'vitals-'.$admission->id" />
      <livewire:admissions.panels.medications :admission-id="$admission->id" :key="'meds-'.$admission->id" />
      <livewire:admissions.panels.nursing-notes :admission-id="$admission->id" :key="'notes-'.$admission->id" />
    </div>
  </div>

  {{-- ── Slide-over: transfer bed ────────────────────────────────── --}}
  <x-ui.modal size="md" show="showTransfer" title="Transfer bed">
    @if($showTransfer)
      <form wire:submit="transfer" style="display:contents;">
        <div class="tb-modal-body">
          <div class="tb-panel">
            <span class="muted tb-small">Currently in {{ $admission->bed?->ward?->name ?? '—' }} · {{ $admission->bed?->name ?? 'no bed' }}.</span>
          </div>

          <x-ui.field label="To bed" name="to_bed_id" required hint="Only available, active beds are listed.">
            <livewire:ui.select-search resource="beds-available" name="to_bed_id" :selected="$to_bed_id"
              placeholder="Search available beds or wards…" wire:key="transfer-bed-picker" />
          </x-ui.field>

          <x-ui.field label="Reason" for="transfer-reason" name="transfer_reason">
            <input id="transfer-reason" type="text" class="tb-input" wire:model="transfer_reason" maxlength="255">
          </x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="transfer">
            <span wire:loading.remove wire:target="transfer"><i class="fas fa-check" aria-hidden="true"></i> Transfer</span>
            <span wire:loading wire:target="transfer"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Moving…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>

  {{-- ── Slide-over: discharge (pessimistic — it bills the stay) ─── --}}
  <x-ui.modal size="md" show="showDischarge" title="Close admission">
    @if($showDischarge)
      <form wire:submit="discharge" style="display:contents;">
        <div class="tb-modal-body">
          <div class="tb-panel">
            <span class="muted tb-small">
              {{ $admission->nights() }} night(s) at
              <x-ui.money :amount="$admission->bed?->daily_charge ?? '0.00'" />/night will be billed to the linked visit.
            </span>
          </div>

          <x-ui.field label="Outcome" for="discharge-outcome" name="outcome" required>
            <select id="discharge-outcome" class="tb-select" wire:model="outcome" required>
              @foreach($outcomes as $o)<option value="{{ $o->value }}">{{ $o->label() }}</option>@endforeach
            </select>
          </x-ui.field>

          <x-ui.field label="Discharge notes" for="discharge-notes" name="discharge_notes">
            <textarea id="discharge-notes" class="tb-input" rows="4" wire:model="discharge_notes" maxlength="5000"></textarea>
          </x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          {{-- D4: the confirmation is on the Livewire action now, not an onclick. --}}
          <button type="submit" class="btn-tb btn-tb-danger"
            wire:confirm="Close this admission? The bed is freed and the stay is billed. This cannot be undone."
            wire:loading.attr="disabled" wire:target="discharge">
            <span wire:loading.remove wire:target="discharge"><i class="fas fa-door-open" aria-hidden="true"></i> Close admission</span>
            <span wire:loading wire:target="discharge"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Closing…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
