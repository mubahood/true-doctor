@php($filtered = $search !== '' || $filterKind !== '' || $from !== '' || $to !== '')
<div>
  <h1 class="sr-only">Journal</h1>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Entry number, description, reference…" aria-label="Search the journal">
    </div>
    <select wire:model.live="filterKind" class="tb-select" aria-label="Kind">
      <option value="">Every kind</option>
      @foreach($kinds as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
    </select>
    <input type="date" wire:model.live="from" class="tb-input fn-date" aria-label="From">
    <input type="date" wire:model.live="to" class="tb-input fn-date" aria-label="To">
    <div wire:loading.flex wire:target="search,filterKind,from,to,gotoPage,previousPage,nextPage,perPage" class="muted tb-small tb-flex" style="gap:6px;"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>

    <div class="tb-toolbar-actions">
      @can('finance.manage')
        <button type="button" class="btn-tb" wire:click="newOpening"><i class="fas fa-flag" aria-hidden="true"></i> Opening balances</button>
        <button type="button" class="btn-tb btn-tb-primary" wire:click="newJournal"><i class="fas fa-plus" aria-hidden="true"></i> New journal</button>
      @endcan
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="search,filterKind,from,to,gotoPage,previousPage,nextPage,perPage"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th>Entry</th><th>Date</th><th>Description</th><th>Kind</th><th class="tb-text-right">Lines</th><th class="tb-text-right">Amount</th></tr></thead>
    <tbody>
      @forelse($rows as $entry)
        <tr wire:key="je-{{ $entry->id }}" @class(['is-muted' => $entry->isReversed()])>
          <td class="tb-nowrap mono tb-small"><button type="button" class="tb-rowbtn" wire:click="peekEntry({{ $entry->id }})">{{ $entry->entry_no }}</button></td>
          <td class="muted tb-nowrap">{{ $entry->date->format('d M Y') }}</td>
          <td>{{ $entry->memo }}@if($entry->isReversed()) <span class="muted tb-xs">· reversed</span>@endif
            @if($entry->party)<div class="muted tb-xs">{{ $entry->party }}</div>@endif</td>
          <td><x-ui.badge :tone="$entry->kind->badge()">{{ $entry->kind->label() }}</x-ui.badge></td>
          <td class="tb-text-right muted">{{ $entry->lines_count }}</td>
          <td class="tb-text-right"><x-ui.money :amount="$entry->total" /></td>
        </tr>
      @empty
        <tr><td colspan="6"><x-ui.empty icon="fa-book" noun="journal entries" :filtered="$filtered" wire:click="$set('search', '')" /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  {{ $rows->links(data: $this->paginationData()) }}

  {{-- ── A journal of one's own ─────────────────────────────────────── --}}
  <x-ui.modal show="showJournal" size="xl" title="New journal">
    @if($showJournal)
      <form wire:submit="saveJournal" style="display:contents;">
        <div class="tb-modal-body">
          <div class="tb-form-grid">
            <x-ui.field label="Date" for="jn-date" name="jDate" required>
              <input id="jn-date" type="date" wire:model="jDate" class="tb-input" required>
            </x-ui.field>
            <x-ui.field label="Reference" for="jn-ref" name="jReference">
              <input id="jn-ref" type="text" wire:model="jReference" class="tb-input" maxlength="120">
            </x-ui.field>
          </div>
          <x-ui.field label="Description" for="jn-memo" name="jMemo" required hint="e.g. Depreciation of medical equipment, October">
            <input id="jn-memo" type="text" wire:model="jMemo" class="tb-input" maxlength="250" required>
          </x-ui.field>

          <div class="tb-table-wrap tb-mt-3"><table class="tb-table fn-jlines">
            <thead><tr><th style="width:38%">Account</th><th>Note</th><th class="tb-text-right" style="width:15%">Debit</th><th class="tb-text-right" style="width:15%">Credit</th><th><span class="sr-only">Remove</span></th></tr></thead>
            <tbody>
              @foreach($jLines as $i => $line)
                <tr wire:key="jl-{{ $i }}">
                  <td>
                    <select wire:model="jLines.{{ $i }}.account_id" class="tb-select" aria-label="Account for line {{ $i + 1 }}">
                      <option value="">Choose…</option>
                      @foreach($this->accountOptions as $group => $opts)
                        <optgroup label="{{ $group }}">@foreach($opts as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</optgroup>
                      @endforeach
                    </select>
                  </td>
                  <td><input type="text" wire:model="jLines.{{ $i }}.memo" class="tb-input" maxlength="250" aria-label="Note for line {{ $i + 1 }}"></td>
                  <td><input type="number" step="0.01" min="0" wire:model.live.debounce.300ms="jLines.{{ $i }}.debit" class="tb-input tb-text-right" aria-label="Debit for line {{ $i + 1 }}"></td>
                  <td><input type="number" step="0.01" min="0" wire:model.live.debounce.300ms="jLines.{{ $i }}.credit" class="tb-input tb-text-right" aria-label="Credit for line {{ $i + 1 }}"></td>
                  <td class="tb-text-right">@if(count($jLines) > 2)<x-ui.icon-button label="Remove line {{ $i + 1 }}" icon="fa-xmark" wire:click="removeLine({{ $i }})" />@endif</td>
                </tr>
              @endforeach
            </tbody>
            <tfoot>
              <tr class="fn-total">
                <td colspan="2"><button type="button" class="tb-linkbtn" wire:click="addLine"><i class="fas fa-plus" aria-hidden="true"></i> Add a line</button></td>
                <td class="tb-text-right"><x-ui.money :amount="$this->journalTotals['debit']" /></td>
                <td class="tb-text-right"><x-ui.money :amount="$this->journalTotals['credit']" /></td>
                <td></td>
              </tr>
            </tfoot>
          </table></div>
          @if((float) $this->journalTotals['difference'] !== 0.0)
            <p class="fn-note is-warn tb-mt-2"><i class="fas fa-scale-unbalanced" aria-hidden="true"></i> Out by {{ \App\Support\HospitalSettings::money(ltrim($this->journalTotals['difference'], '-')) }} — debits and credits must be equal.</p>
          @endif
          @error('jLines')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="saveJournal">
            <span wire:loading.remove wire:target="saveJournal"><i class="fas fa-check" aria-hidden="true"></i> Post journal</span>
            <span wire:loading wire:target="saveJournal"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Posting…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>

  {{-- ── Opening balances ───────────────────────────────────────────── --}}
  <x-ui.modal show="showOpening" size="lg" title="Opening balances">
    @if($showOpening)
      <form wire:submit="saveOpening" style="display:contents;">
        <div class="tb-modal-body">
          <p class="muted tb-small">What the hospital had and owed on the day it started keeping its books here. Enter each as the account normally reads — cash you hold, money you owe — and a negative figure for an overdrawn account. What the hospital was worth is worked out for you and put to opening balance equity.</p>
          @if($this->hasOpening)
            <p class="fn-note is-warn"><i class="fas fa-circle-exclamation" aria-hidden="true"></i> Opening balances have been entered before. These will be added to them — reverse the earlier entry in the journal first if you are replacing it.</p>
          @endif
          <x-ui.field label="As at" for="ob-date" name="oDate" required hint="Usually the day before your first transaction here.">
            <input id="ob-date" type="date" wire:model="oDate" class="tb-input" required>
          </x-ui.field>
          <div class="tb-table-wrap tb-mt-3"><table class="tb-table">
            <thead><tr><th>Account</th><th>Kind</th><th class="tb-text-right" style="width:30%">Balance</th></tr></thead>
            <tbody>
              @foreach($this->openingAccounts as $a)
                <tr wire:key="ob-{{ $a->id }}">
                  <td><label for="ob-{{ $a->id }}">{{ $a->label() }}</label></td>
                  <td class="muted tb-small">{{ $a->type->label() }}{{ $a->is_book ? ' · book' : '' }}</td>
                  <td><input id="ob-{{ $a->id }}" type="number" step="0.01" wire:model="oBalances.{{ $a->id }}" class="tb-input tb-text-right"></td>
                </tr>
              @endforeach
            </tbody>
          </table></div>
          @error('oBalances')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
          <p class="muted tb-xs tb-mt-2">Stock on the shelf is not entered here — it is valued from the stock ledger as items are received.</p>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="saveOpening">
            <span wire:loading.remove wire:target="saveOpening"><i class="fas fa-check" aria-hidden="true"></i> Post opening balances</span>
            <span wire:loading wire:target="saveOpening"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Posting…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>

  @include('livewire.finance.partials.entry-peek')
</div>
