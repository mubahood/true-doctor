<div>
  <h1 class="sr-only">Patients</h1>
  
  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap" style="position:relative;">
      <i class="fas fa-magnifying-glass" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--mt2);font-size:12px;"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Search name, number or phone…" style="padding-left:32px;min-width:240px;">
    </div>
    <select wire:model.live="statusFilter" class="tb-select">
      <option value="">All statuses</option>
      @foreach($statuses as $val => $label)
        <option value="{{ $val }}">{{ $label }}</option>
      @endforeach
    </select>
    <div wire:loading.flex wire:target="search,statusFilter" class="muted" style="font-size:.8rem;align-items:center;gap:6px;"><i class="fas fa-circle-notch fa-spin"></i> Loading…</div>
  
    <div class="tb-toolbar-actions">
      @can('create', \App\Models\Patient::class)
      <button type="button" wire:click="create" class="btn-tb btn-tb-primary"><i class="fas fa-plus"></i> Register patient</button>
    @endcan
    </div>
  </div>

  <div class="tb-card" wire:loading.class="tb-loading" wire:target="search,statusFilter">
    <div class="tb-table-wrap">
      <table class="tb-table">
        <thead>
          <tr>
            <th class="tb-serial">#</th><th wire:click="sortBy('patient_no')" style="cursor:pointer;user-select:none;">Patient no. @include('livewire.partials.sort-caret', ['field' => 'patient_no'])</th>
            <th wire:click="sortBy('first_name')" style="cursor:pointer;user-select:none;">Name @include('livewire.partials.sort-caret', ['field' => 'first_name'])</th>
            <th>Sex</th>
            <th>Age</th>
            <th>Phone</th>
            <th wire:click="sortBy('status')" style="cursor:pointer;user-select:none;">Status @include('livewire.partials.sort-caret', ['field' => 'status'])</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          @forelse($patients as $patient)
            <tr wire:key="patient-{{ $patient->id }}">
              <x-ui.serial :rows="$patients" :loop="$loop" />
              {{-- Opens the patient over the list, like everywhere else. --}}
              <td class="mono"><button type="button" class="tb-rowbtn" wire:click="peek({{ $patient->id }})" title="See this patient">{{ $patient->patient_no }}</button></td>
              <td style="font-weight:500;"><button type="button" class="tb-rowbtn" wire:click="peek({{ $patient->id }})" title="See this patient">{{ $patient->full_name }}</button></td>
              <td class="muted">{{ $patient->sex?->label() ?? '—' }}</td>
              <td class="muted">{{ $patient->age() ?? '—' }}</td>
              <td class="muted">{{ $patient->phone_1 ?? '—' }}</td>
              <td><span class="badge-tb {{ $patient->status->badge() }}">{{ $patient->status->label() }}</span></td>
              <td style="white-space:nowrap;">
                <button type="button" class="btn-tb btn-tb-ghost btn-tb-icon" wire:click="peek({{ $patient->id }})"
                        aria-label="See {{ $patient->full_name }}"><i class="fas fa-eye" aria-hidden="true"></i></button>
                @can('update', $patient)<button type="button" class="btn-tb btn-tb-ghost btn-tb-icon" wire:click="edit({{ $patient->id }})" aria-label="Edit {{ $patient->full_name }}"><i class="fas fa-pen" aria-hidden="true"></i></button>@endcan
                <x-ui.actions-menu label="Actions for {{ $patient->full_name }}">
                  <button type="button" role="menuitem" wire:click="peek({{ $patient->id }})"><i class="fas fa-eye" aria-hidden="true"></i> Quick view</button>
                  <a role="menuitem" wire:navigate href="{{ route('admin.patients.show', $patient) }}"><i class="fas fa-folder-open" aria-hidden="true"></i> Full record</a>
                  <a role="menuitem" href="{{ route('admin.patients.id-card', $patient) }}" target="_blank" rel="noopener"><i class="fas fa-id-card" aria-hidden="true"></i> ID card</a>
                </x-ui.actions-menu>
              </td>
            </tr>
          @empty
            <tr><td colspan="8">
              <x-ui.empty icon="fa-user-injured" noun="patients"
                          :filtered="$search !== '' || $statusFilter !== ''"
                          wire:click="$set('search', '')" />
            </td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  {{ $patients->links(data: $this->paginationData()) }}

  {{-- ── AJAX modal: register / edit patient ───────────────────── --}}
  <x-ui.modal size="xl" show="showForm" :title="$editingId ? 'Edit patient' : 'Register patient'">
    @if($showForm)
    <form wire:submit="save" style="display:contents;">
      <div class="tb-modal-body">
        @include('livewire.patients._fields')
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
          <span wire:loading.remove wire:target="save"><i class="fas fa-check"></i> {{ $editingId ? 'Save changes' : 'Register patient' }}</span>
          <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin"></i> Saving…</span>
        </button>
      </div>
    </form>
  @endif
  </x-ui.modal>

  @include('livewire.partials.patient-peek')
</div>
