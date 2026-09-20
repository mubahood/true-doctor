<div>
  <h1 class="sr-only">Insurance claims</h1>

  @php($targets = 'search,gotoPage,previousPage,nextPage,perPage,status')

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Claim no. or patient…" aria-label="Search insurance claims">
    </div>
    <select wire:model.live="status" class="tb-select" aria-label="Filter by status"><option value="">All statuses</option>
      @foreach($this->statuses as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
    </select>
    <div wire:loading.flex wire:target="{{ $targets }}" class="muted tb-small tb-flex" style="gap:6px;"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>

    <div class="tb-toolbar-actions">
      @can('manage', \App\Models\InsuranceClaim::class)
        <button type="button" wire:click="create" class="btn-tb btn-tb-primary"><i class="fas fa-plus" aria-hidden="true"></i> New claim</button>
      @endcan
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="{{ $targets }}"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th class="tb-serial">#</th><th>Claim</th><th>Patient</th><th>Provider</th><th class="tb-text-right">Amount</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
      @forelse($rows as $c)
        <tr wire:key="claim-{{ $c->id }}">
          <x-ui.serial :rows="$rows" :loop="$loop" />
          {{-- Opens the claim over the list, like everywhere else. --}}
          <td class="mono"><button type="button" class="tb-rowbtn" wire:click="peek({{ $c->id }})" title="See this claim">{{ $c->claim_no }}</button></td>
          <td class="tb-fw-500">{{ $c->patient?->full_name }}</td>
          <td class="muted">{{ $c->provider?->name }}</td>
          <td class="tb-text-right mono">{{ \App\Support\HospitalSettings::money($c->amount) }}</td>
          <td><x-ui.badge :tone="$c->status->badge()">{{ $c->status->label() }}</x-ui.badge></td>
          <td class="tb-text-right tb-nowrap">
            <button type="button" class="btn-tb btn-tb-ghost btn-tb-icon" wire:click="peek({{ $c->id }})"
                    aria-label="See claim {{ $c->claim_no }}"><i class="fas fa-eye" aria-hidden="true"></i></button>
            <x-ui.actions-menu label="Actions for claim {{ $c->claim_no }}">
              <button type="button" role="menuitem" wire:click="peek({{ $c->id }})"><i class="fas fa-eye" aria-hidden="true"></i> Quick view</button>
              <a role="menuitem" wire:navigate href="{{ route('admin.insurance-claims.show', $c) }}"><i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i> Full record</a>
            </x-ui.actions-menu>
          </td>
        </tr>
      @empty
        <tr><td colspan="7"><x-ui.empty icon="fa-file-medical" noun="claims" :filtered="$search !== '' || $status !== ''" wire:click="$set('search', '')" /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  {{ $rows->links(data: $this->paginationData()) }}

  {{-- ── Slide-over: new claim ─────────────────────────────────── --}}
  <x-ui.modal size="xl" show="showForm" title="New insurance claim">
    @if($showForm)
    <form wire:submit="save" style="display:contents;">
      <div class="tb-modal-body">
        <x-ui.field label="Patient" name="patient_id" required>
          <livewire:ui.select-search resource="patients" name="patient_id" :selected="$patient_id"
            placeholder="Search patients by name, number or phone…" wire:key="claim-patient-picker" />
        </x-ui.field>
        <x-ui.field label="Insurance provider" for="claim-provider" name="insurance_provider_id" required>
          <select id="claim-provider" wire:model="insurance_provider_id" class="tb-select">
            <option value="">— select —</option>
            @foreach($this->providers as $pr)<option value="{{ $pr->id }}">{{ $pr->name }}</option>@endforeach
          </select>
        </x-ui.field>
        <x-ui.field label="Invoice" name="invoice_id" required hint="Only issued or partially paid invoices can be claimed.">
          <livewire:ui.select-search resource="invoices-open" name="invoice_id" :selected="$invoice_id"
            placeholder="Search open invoices by number or patient…" wire:key="claim-invoice-picker" />
        </x-ui.field>
        <x-ui.field label="Claim amount" for="claim-amount" name="amount" required>
          <input id="claim-amount" type="number" step="0.01" wire:model="amount" class="tb-input" required>
        </x-ui.field>
        <x-ui.field label="Notes" for="claim-notes" name="notes">
          <textarea id="claim-notes" wire:model="notes" class="tb-textarea" rows="2"></textarea>
        </x-ui.field>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
          <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> Create claim</span>
          <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
        </button>
      </div>
    </form>
    @endif
  </x-ui.modal>

  @include('livewire.partials.claim-peek')
</div>
