<div>
  <x-ui.page-header title="Remove test records">
    <x-slot:explain>
      Patients made while learning the system, removed with everything attached: visits, orders, bills, payments, the chart, notes, documents and messages.
      What was taken off the shelf for them goes back, and the books are put right with reversing entries dated today.
      @if($backsUp) A backup of the whole system is made first. @endif
      Staff accounts are removed on <x-ui.link :href="route('admin.users.index')">User accounts</x-ui.link>.
    </x-slot:explain>
  </x-ui.page-header>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Name contains…" aria-label="Name contains">
    </div>
    <label class="tb-small tb-flex rt-filter" for="rt-before">
      Created before
      <input id="rt-before" type="date" wire:model.live="before" class="tb-input">
    </label>
    <label class="tb-check-group">
      <input type="checkbox" wire:model.live="unpaid"> No payments
    </label>

    <div class="tb-toolbar-actions">
      <button type="button" class="btn-tb btn-tb-sm" wire:click="pickShown">Select all shown</button>
      @if($picked !== [])
        <button type="button" class="btn-tb btn-tb-sm btn-tb-ghost" wire:click="clearPicked">Clear ({{ count($picked) }})</button>
      @endif
      <button type="button" class="btn-tb btn-tb-sm btn-tb-danger" wire:click="confirm" @disabled($picked === [])>
        <i class="fas fa-trash" aria-hidden="true"></i> Remove {{ count($picked) ?: '' }} {{ \Illuminate\Support\Str::plural('patient', max(1, count($picked))) }}
      </button>
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="search,before,unpaid,gotoPage,previousPage,nextPage,perPage"><div class="tb-table-wrap"><table class="tb-table">
    <caption class="sr-only">Patients that can be removed</caption>
    <thead><tr><th class="tb-serial">#</th><th class="rt-pick"><span class="sr-only">Remove</span></th><th>Patient</th><th>Visits</th><th>Billed</th><th>Paid</th><th>Created</th></tr></thead>
    <tbody>
      @forelse($rows as $p)
        <tr wire:key="rt-{{ $p->id }}">
          <x-ui.serial :rows="$rows" :loop="$loop" />
          <td class="rt-pick"><input type="checkbox" wire:model.live="picked" value="{{ $p->id }}" aria-label="Remove {{ $p->full_name }}"></td>
          <td class="tb-fw-500">
            <x-ui.link :href="route('admin.patients.show', $p)">{{ $p->full_name }}</x-ui.link>
            <div class="mono muted tb-xs">{{ $p->patient_no }}</div>
          </td>
          <td class="tb-small">{{ number_format((int) $p->visit_count) }}</td>
          <td class="tb-small"><x-ui.money :amount="(string) $p->billed_total" /></td>
          <td class="tb-small"><x-ui.money :amount="(string) $p->paid_total" /></td>
          <td class="tb-small tb-nowrap">{{ $p->created_at?->format('d M Y H:i') }}</td>
        </tr>
      @empty
        <tr><td colspan="7"><x-ui.empty icon="fa-users" noun="patients" :filtered="$filtered" wire:click="$set('search', '')" /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  {{ $rows->links(data: $this->paginationData()) }}

  <x-ui.modal size="sm" show="confirming" title="Remove test records">
    @if($confirming)
      <form wire:submit="remove" class="vd-form">
        <div class="tb-modal-body">
          <p class="tb-small tb-mb-3">
            {{ count($picked) }} {{ \Illuminate\Support\Str::plural('patient', count($picked)) }} and everything attached to them will be removed for good.
            The books keep a reversing entry for every amount.
            @if($backsUp) A backup is made first. @endif
          </p>
          <x-ui.field label="Type the hospital's name to confirm" for="rt-name" name="typedName" required :hint="$hospitalName">
            <input id="rt-name" type="text" class="tb-input" wire:model="typedName" autocomplete="off">
          </x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="closeConfirm">Keep them</button>
          <button type="submit" class="btn-tb btn-tb-danger" wire:loading.attr="disabled" wire:target="remove">
            <span wire:loading.remove wire:target="remove"><i class="fas fa-trash" aria-hidden="true"></i> Remove</span>
            <span wire:loading wire:target="remove">Removing…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
