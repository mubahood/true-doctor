@php($filtered = $search !== '' || $filterBook !== '' || $filterKind !== '' || $filterDirection !== '' || $from !== '' || $to !== '')
<div>
  <h1 class="sr-only">Transactions</h1>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Description, payee, reference…" aria-label="Search transactions">
    </div>
    <select wire:model.live="filterBook" class="tb-select" aria-label="Book">
      <option value="">Every book</option>
      @foreach($this->books as $b)<option value="{{ $b->id }}">{{ $b->name }}{{ $b->is_active ? '' : ' (off)' }}</option>@endforeach
    </select>
    <select wire:model.live="filterDirection" class="tb-select" aria-label="Direction">
      <option value="">In and out</option><option value="in">Money in</option><option value="out">Money out</option>
    </select>
    <select wire:model.live="filterKind" class="tb-select" aria-label="Kind">
      <option value="">Every kind</option>
      @foreach($kinds as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
    </select>
    <input type="date" wire:model.live="from" class="tb-input fn-date" aria-label="From">
    <input type="date" wire:model.live="to" class="tb-input fn-date" aria-label="To">
    <div wire:loading.flex wire:target="search,filterBook,filterKind,filterDirection,from,to,gotoPage,previousPage,nextPage,perPage" class="muted tb-small tb-flex" style="gap:6px;"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>

    <div class="tb-toolbar-actions">
      @can('finance.record')
        <button type="button" class="btn-tb" wire:click="openMoney('transfer')"><i class="fas fa-right-left" aria-hidden="true"></i> Transfer</button>
        <button type="button" class="btn-tb" wire:click="openMoney('out')"><i class="fas fa-arrow-up" aria-hidden="true"></i> Money out</button>
        <button type="button" class="btn-tb btn-tb-primary" wire:click="openMoney('in')"><i class="fas fa-arrow-down" aria-hidden="true"></i> Money in</button>
      @endcan
      <a href="{{ route('admin.finance.export', ['what' => 'transactions', 'book' => $filterBook, 'kind' => $filterKind, 'dir' => $filterDirection, 'from' => $from, 'to' => $to, 'q' => $search]) }}" class="btn-tb btn-tb-ghost" title="Download these as a spreadsheet"><i class="fas fa-file-csv" aria-hidden="true"></i> CSV</a>
    </div>
  </div>

  <div class="fn-presets tb-mb-3" role="group" aria-label="Quick periods">
    @foreach($presets as $key => $label)
      <button type="button" class="fn-preset" wire:click="preset('{{ $key }}')">{{ $label }}</button>
    @endforeach
    @if($filtered)<button type="button" class="tb-linkbtn tb-small" wire:click="clearFilters">Clear filters</button>@endif
    @if($this->bookBalance !== null)
      <span class="fn-book-chip">Balance today: <b class="mono">{{ \App\Support\HospitalSettings::money($this->bookBalance) }}</b></span>
    @endif
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="search,filterBook,filterKind,filterDirection,from,to,gotoPage,previousPage,nextPage,perPage"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr>
      <th>Date</th><th>Description</th><th>Book</th><th>For</th><th>Kind</th>
      <th class="tb-text-right">In</th><th class="tb-text-right">Out</th>
    </tr></thead>
    <tbody>
      @forelse($rows as $line)
        @php($entry = $line->entry)
        @php($others = $entry->lines->where('id', '!=', $line->id)->reject(fn ($l) => $l->ledger_account_id === $line->ledger_account_id))
        <tr wire:key="tx-{{ $line->id }}" @class(['is-muted' => $entry->isReversed()])>
          <td class="muted tb-nowrap">{{ $line->date->format('d M Y') }}</td>
          <td>
            <button type="button" class="tb-rowbtn" wire:click="peekEntry({{ $entry->id }})">{{ $entry->memo }}</button>
            <div class="muted tb-xs">{{ $entry->entry_no }}@if($entry->party) · {{ $entry->party }}@endif @if($entry->reference) · {{ $entry->reference }}@endif @if($entry->isReversed()) · <i>reversed</i>@endif</div>
          </td>
          <td class="tb-small">{{ $line->account->name }}</td>
          <td class="tb-small muted">{{ $others->pluck('account.name')->unique()->take(2)->implode(', ') }}{{ $others->pluck('account.name')->unique()->count() > 2 ? '…' : '' }}</td>
          <td><x-ui.badge :tone="$entry->kind->badge()">{{ $entry->kind->label() }}</x-ui.badge></td>
          <td class="tb-text-right fn-in">@if((float) $line->debit > 0)<x-ui.money :amount="$line->debit" />@endif</td>
          <td class="tb-text-right fn-out">@if((float) $line->credit > 0)<x-ui.money :amount="$line->credit" />@endif</td>
        </tr>
      @empty
        <tr><td colspan="7"><x-ui.empty icon="fa-money-bill-transfer" noun="transactions" :filtered="$filtered" wire:click="clearFilters" /></td></tr>
      @endforelse
    </tbody>
    @if($rows->isNotEmpty())
      <tfoot><tr class="fn-total"><td colspan="5">This page</td><td class="tb-text-right"><x-ui.money :amount="$pageIn" /></td><td class="tb-text-right"><x-ui.money :amount="$pageOut" /></td></tr></tfoot>
    @endif
  </table></div></div>
  {{ $rows->links(data: $this->paginationData()) }}

  @include('livewire.finance.partials.money-form')
  @include('livewire.finance.partials.entry-peek')
</div>
