<div>
  <h1 class="sr-only">Admissions</h1>

  @php($targets = 'search,gotoPage,previousPage,nextPage,perPage,status')

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Search patient…" aria-label="Search admissions by patient">
    </div>
    <select wire:model.live="status" class="tb-select" aria-label="Filter by status"><option value="">Active</option>
      @foreach($this->statuses as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
    </select>
    <div wire:loading.flex wire:target="{{ $targets }}" class="muted tb-small tb-flex" style="gap:6px;"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>

    <div class="tb-toolbar-actions">
      <a wire:navigate href="{{ route('admin.admissions.board') }}" class="btn-tb"><i class="fas fa-table-cells" aria-hidden="true"></i> Occupancy</a>
      @can('manage', \App\Models\Admission::class)
        <button type="button" wire:click="create" class="btn-tb btn-tb-primary"><i class="fas fa-plus" aria-hidden="true"></i> Admit</button>
      @endcan
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="{{ $targets }}"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th class="tb-serial">#</th><th>Patient</th><th>Ward / Bed</th><th>Admitted</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
      @forelse($rows as $a)
        <tr wire:key="adm-{{ $a->id }}">
          <x-ui.serial :rows="$rows" :loop="$loop" />
          {{-- Opens the stay over the list, like everywhere else. --}}
          <td class="tb-fw-500">
            <button type="button" class="tb-rowbtn" wire:click="peek({{ $a->id }})"
                    title="See this stay">{{ $a->patient?->full_name }}</button>
          </td>
          <td class="muted">{{ $a->bed?->ward?->name }} · {{ $a->bed?->name ?? '—' }}</td>
          <td class="muted">{{ $a->admitted_at->format('d M H:i') }}</td>
          <td><x-ui.badge :tone="$a->status->badge()">{{ $a->status->label() }}</x-ui.badge></td>
          <td class="tb-text-right tb-nowrap">
            <x-ui.icon-button label="See this stay" icon="fa-eye" wire:click="peek({{ $a->id }})" />
            <x-ui.actions-menu label="Actions for {{ $a->patient?->full_name ?? 'this admission' }}">
              <button type="button" role="menuitem" wire:click="peek({{ $a->id }})"><i class="fas fa-eye" aria-hidden="true"></i> Quick view</button>
              <a role="menuitem" wire:navigate href="{{ route('admin.admissions.show', $a) }}"><i class="fas fa-folder-open" aria-hidden="true"></i> Full record</a>
              @if($a->patient)
                <a role="menuitem" wire:navigate href="{{ route('admin.patients.show', $a->patient) }}"><i class="fas fa-user" aria-hidden="true"></i> The patient</a>
              @endif
            </x-ui.actions-menu>
          </td>
        </tr>
      @empty
        <tr><td colspan="6"><x-ui.empty icon="fa-bed" noun="admissions" :filtered="$search !== '' || $status !== ''" wire:click="$set('search', '')" /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  {{ $rows->links(data: $this->paginationData()) }}

  {{-- ── Slide-over: admit ─────────────────────────────────────── --}}
  {{-- The admit dialog is shared with the occupancy board, so the two screens
       cannot come to raise a stay differently. --}}
  @include('livewire.partials.admit-dialog', ['admitAction' => 'save'])

  @include('livewire.partials.admission-peek')
</div>
