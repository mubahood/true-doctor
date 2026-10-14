@php($a = $this->account)
@php($st = $this->statement)
<div>
  <x-ui.page-header :title="$a->name" :crumbs="['Chart of accounts' => route('admin.finance.accounts'), $a->name => null]">
    <x-slot:subtitle>
      <div class="tb-flex tb-mt-2">
        <span class="mono muted tb-small">{{ $a->code }}</span>
        <x-ui.badge :tone="$a->type->badge()">{{ $a->type->label() }}</x-ui.badge>
        @if($a->is_book)<x-ui.badge tone="info">Book</x-ui.badge>@endif
        @unless($a->is_active)<x-ui.badge tone="neutral">Switched off</x-ui.badge>@endunless
        @if($a->description)<span class="muted tb-small">{{ $a->description }}</span>@endif
      </div>
    </x-slot:subtitle>
    <x-slot:actions>
      <a href="{{ route('admin.finance.export', ['what' => 'ledger', 'account' => $a->id, 'from' => $from, 'to' => $to]) }}" class="btn-tb btn-tb-ghost"><i class="fas fa-file-csv" aria-hidden="true"></i> CSV</a>
    </x-slot:actions>
  </x-ui.page-header>

  <div class="tb-filter-bar tb-toolbar">
    <div class="fn-presets" role="group" aria-label="Period">
      @foreach($presets as $key => $label)
        <button type="button" class="fn-preset" wire:click="preset('{{ $key }}')">{{ $label }}</button>
      @endforeach
    </div>
    <input type="date" wire:model.live="from" class="tb-input fn-date" aria-label="From">
    <input type="date" wire:model.live="to" class="tb-input fn-date" aria-label="To">
  </div>

  <div class="tb-stats-grid">
    <x-dash.stat icon="fa-flag" :value="\App\Support\HospitalSettings::money($st['opening'])" label="Opening balance" :sub="'Before '.\Illuminate\Support\Carbon::parse($from)->format('d M Y')" />
    <x-dash.stat icon="fa-plus" :value="\App\Support\HospitalSettings::money($st['debit'])" label="Debits" sub="In the period" />
    <x-dash.stat icon="fa-minus" :value="\App\Support\HospitalSettings::money($st['credit'])" label="Credits" sub="In the period" />
    <x-dash.stat icon="fa-flag-checkered" :tone="(float) $st['closing'] < 0 ? 'bad' : ''" :value="\App\Support\HospitalSettings::money($st['closing'])" label="Closing balance" :sub="'On '.\Illuminate\Support\Carbon::parse($to)->format('d M Y')" />
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="from,to,preset"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th>Date</th><th>Entry</th><th>Description</th><th class="tb-text-right">Debit</th><th class="tb-text-right">Credit</th><th class="tb-text-right">Balance</th></tr></thead>
    <tbody>
      <tr class="fn-total"><td colspan="5">Opening balance</td><td class="tb-text-right"><x-ui.money :amount="$st['opening']" /></td></tr>
      @forelse($st['lines'] as $row)
        @php($line = $row['line'])
        <tr wire:key="al-{{ $line->id }}">
          <td class="muted tb-nowrap">{{ $line->date->format('d M Y') }}</td>
          <td class="mono tb-small"><button type="button" class="tb-rowbtn" wire:click="peekEntry({{ $line->journal_entry_id }})">{{ $line->entry->entry_no }}</button></td>
          <td>{{ $line->memo ?: $line->entry->memo }}@if($line->entry->party)<div class="muted tb-xs">{{ $line->entry->party }}</div>@endif</td>
          <td class="tb-text-right">@if((float) $line->debit > 0)<x-ui.money :amount="$line->debit" />@endif</td>
          <td class="tb-text-right">@if((float) $line->credit > 0)<x-ui.money :amount="$line->credit" />@endif</td>
          <td class="tb-text-right"><x-ui.money :amount="$row['balance']" /></td>
        </tr>
      @empty
        <tr><td colspan="6"><x-ui.empty compact icon="fa-book-open" message="Nothing moved in this account in the period." /></td></tr>
      @endforelse
    </tbody>
    <tfoot><tr class="fn-total"><td colspan="3">Closing balance</td><td class="tb-text-right"><x-ui.money :amount="$st['debit']" /></td><td class="tb-text-right"><x-ui.money :amount="$st['credit']" /></td><td class="tb-text-right"><x-ui.money :amount="$st['closing']" /></td></tr></tfoot>
  </table></div></div>

  @include('livewire.finance.partials.entry-peek')
</div>
