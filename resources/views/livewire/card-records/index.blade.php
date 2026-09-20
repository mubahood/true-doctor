<div>
  <h1 class="sr-only">Card records</h1>

  {{-- A ledger is read to a total. In, out, and the difference between them. --}}
  <div class="tb-stats-grid tb-mb-4">
    <x-dash.stat icon="fa-arrow-up" tone="ok"
                 :value="\App\Support\HospitalSettings::money($this->totals['in'])" label="Money in"
                 sub="Top-ups and settlements" />
    <x-dash.stat icon="fa-arrow-down"
                 :value="\App\Support\HospitalSettings::money($this->totals['out'])" label="Money out"
                 sub="Charged to cards" />
    <x-dash.stat icon="fa-scale-balanced"
                 :tone="bccomp($this->totals['net'], '0', 2) < 0 ? 'warn' : ''"
                 :value="\App\Support\HospitalSettings::money($this->totals['net'])" label="Net" />
  </div>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap" style="position:relative;">
      <i class="fas fa-magnifying-glass" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--mt2);font-size:12px;"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input"
             placeholder="Patient, note or reference…" style="padding-left:32px;min-width:240px;">
    </div>

    <select wire:model.live="type" class="tb-select">
      <option value="">In and out</option>
      @foreach($types as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
    </select>

    <select wire:model.live="insurer" class="tb-select">
      <option value="">Any card</option>
      <option value="insured">Any insurer</option>
      @foreach($this->insurers as $insurer)
        <option value="{{ $insurer->id }}">{{ $insurer->name }}</option>
      @endforeach
    </select>

    <x-ui.field label="From" for="rec-from" name="from">
      <input id="rec-from" type="date" wire:model.live="from" class="tb-input">
    </x-ui.field>
    <x-ui.field label="To" for="rec-to" name="to">
      <input id="rec-to" type="date" wire:model.live="to" class="tb-input">
    </x-ui.field>

    <div wire:loading.flex wire:target="search,type,insurer,from,to" class="muted"
         style="font-size:.8rem;align-items:center;gap:6px;">
      <i class="fas fa-circle-notch fa-spin"></i> Loading…
    </div>
  </div>

  <div class="tb-card" wire:loading.class="tb-loading" wire:target="search,type,insurer,from,to">
    <div class="tb-table-wrap">
      <table class="tb-table">
        <caption class="sr-only">Every movement on every card in the hospital</caption>
        <thead>
          <tr>
            <th class="tb-serial">#</th><th>When</th><th>Card</th><th>Spent on</th><th>What</th>
            <th style="text-align:right;">Amount</th><th style="text-align:right;">Balance</th><th>By</th>
            <th><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          @forelse($rows as $record)
            <tr wire:key="rec-{{ $record->id }}">
              <x-ui.serial :rows="$rows" :loop="$loop" />
              <td class="tb-nowrap muted">{{ $record->created_at?->format('d M Y H:i') }}</td>
              <td class="mono">
                @if($record->card)
                  {{-- Opens the entry over the ledger, like everywhere else. --}}
                  <button type="button" class="tb-rowbtn" wire:click="peek({{ $record->id }})" title="See this entry">{{ $record->card->masked() }}</button>
                  @if($record->card->provider)
                    <span class="muted" style="font-size:.78rem;">{{ $record->card->provider->name }}</span>
                  @endif
                @else — @endif
              </td>
              <td>{{ $record->patient?->full_name ?? '—' }}</td>
              <td>
                {{ $record->description ?? $record->type->label() }}
                @if($record->reference)
                  <span class="muted mono" style="font-size:.78rem;">{{ $record->reference }}</span>
                @endif
              </td>
              <td style="text-align:right;" class="mono {{ $record->type === \App\Enums\CardEntryType::Credit ? 'tb-primary' : 'tb-danger' }}">
                {{ $record->type === \App\Enums\CardEntryType::Credit ? '+' : '−' }}{{ \App\Support\HospitalSettings::money($record->amount) }}
              </td>
              <td style="text-align:right;" class="mono muted">{{ \App\Support\HospitalSettings::money($record->balance_after) }}</td>
              <td class="muted">{{ $record->createdBy?->name ?? '—' }}</td>
              <td style="text-align:right;">
                <button type="button" class="btn-tb btn-tb-ghost btn-tb-icon" wire:click="peek({{ $record->id }})"
                        aria-label="See this entry"><i class="fas fa-eye" aria-hidden="true"></i></button>
              </td>
            </tr>
          @empty
            <tr><td colspan="9"><x-ui.empty icon="fa-receipt" noun="card records" :filtered="$search !== '' || $type !== '' || $insurer !== '' || $from !== '' || $to !== ''" /></td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  {{ $rows->links(data: $this->paginationData()) }}

  @include('livewire.partials.card-record-peek')
</div>
