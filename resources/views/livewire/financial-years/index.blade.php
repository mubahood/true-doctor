<div>
  <h1 class="sr-only">Financial years</h1>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Search period…" aria-label="Search financial years">
    </div>
    <div wire:loading.flex wire:target="search,gotoPage,previousPage,nextPage,perPage" class="muted tb-small tb-flex" style="gap:6px;"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>

    <div class="tb-toolbar-actions">
      @can('manage', \App\Models\FinancialYear::class)<button type="button" wire:click="create" class="btn-tb btn-tb-primary"><i class="fas fa-plus" aria-hidden="true"></i> New period</button>@endcan
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="search,gotoPage,previousPage,nextPage,perPage"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th class="tb-serial">#</th><th>Period</th><th>From</th><th>To</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
      @forelse($rows as $y)
        <tr wire:key="fy-{{ $y->id }}">
          <x-ui.serial :rows="$rows" :loop="$loop" />
          {{-- Opens the period over the list, like everywhere else. --}}
          <td class="tb-fw-500"><button type="button" class="tb-rowbtn" wire:click="peek({{ $y->id }})" title="See this period">{{ $y->name }}</button></td>
          <td class="muted">{{ $y->starts_on->format('d M Y') }}</td>
          <td class="muted">{{ $y->ends_on->format('d M Y') }}</td>
          <td><x-ui.badge :tone="$y->status->badge()">{{ $y->status->label() }}</x-ui.badge></td>
          <td class="tb-text-right tb-nowrap">
            <x-ui.icon-button label="See {{ $y->name }}" icon="fa-eye" wire:click="peek({{ $y->id }})" />
            <x-ui.icon-button label="Full report for {{ $y->name }}" icon="fa-chart-column" :href="route('admin.financial-years.show', $y)" />
            @can('manage', \App\Models\FinancialYear::class)
              @if($y->isOpen())
                <button type="button" class="btn-tb btn-tb-sm" wire:click="close({{ $y->id }})" wire:confirm="Close this period? New invoices dated within it will be blocked.">Close</button>
              @else
                <button type="button" class="btn-tb btn-tb-sm btn-tb-ghost" wire:click="reopen({{ $y->id }})" wire:confirm="Reopen this closed period? Postings into it will be allowed again.">Reopen</button>
              @endif
            @endcan
          </td>
        </tr>
      @empty
        <tr><td colspan="6"><x-ui.empty icon="fa-calendar-alt" noun="financial years" :filtered="$search !== ''" wire:click="$set('search', '')" /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  {{ $rows->links(data: $this->paginationData()) }}

  {{-- ── Slide-over: open a new period ─────────────────────────── --}}
  <x-ui.modal size="md" show="showForm" title="New financial year">
    @if($showForm)
    <form wire:submit="save" style="display:contents;">
      <div class="tb-modal-body">
        <x-ui.field label="Name" for="fy-name" name="name" required>
          <input id="fy-name" type="text" wire:model="name" class="tb-input" placeholder="e.g. FY 2026" required>
        </x-ui.field>
        <div class="tb-form-grid">
          <x-ui.field label="Starts on" for="fy-start" name="starts_on" required>
            <input id="fy-start" type="date" wire:model="starts_on" class="tb-input" required>
          </x-ui.field>
          <x-ui.field label="Ends on" for="fy-end" name="ends_on" required>
            <input id="fy-end" type="date" wire:model="ends_on" class="tb-input" required>
          </x-ui.field>
        </div>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
          <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> Create period</span>
          <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
        </button>
      </div>
    </form>
    @endif
  </x-ui.modal>

  @include('livewire.partials.financial-year-peek')
</div>
