{{-- One journal entry over the list (App\Livewire\Concerns\PeeksJournalEntries). --}}
<x-ui.modal show="showEntry" size="lg" :title="$this->peekedEntry?->entry_no ?? 'Entry'">
  @if($showEntry && ($entry = $this->peekedEntry))
    <div class="tb-modal-body">
      <div class="fn-entry-head">
        <div>
          <div class="fn-entry-memo">{{ $entry->memo }}</div>
          <div class="muted tb-small tb-mt-2">
            {{ $entry->date->format('d M Y') }}
            @if($entry->party) · {{ $entry->party }}@endif
            @if($entry->reference) · Ref {{ $entry->reference }}@endif
            @if($entry->createdBy) · by {{ $entry->createdBy->name }}@endif
          </div>
        </div>
        <x-ui.badge :tone="$entry->kind->badge()"><i class="fas {{ $entry->kind->icon() }}" aria-hidden="true"></i> {{ $entry->kind->label() }}</x-ui.badge>
      </div>

      @if($entry->isReversed())
        <div class="fn-note is-warn"><i class="fas fa-rotate-left" aria-hidden="true"></i>
          Reversed by <button type="button" class="tb-linkbtn" wire:click="peekEntry({{ $entry->reversed_by_id }})">{{ $entry->reversedBy?->entry_no }}</button>.</div>
      @endif
      @if($entry->reverses)
        <div class="fn-note"><i class="fas fa-circle-info" aria-hidden="true"></i>
          Reverses <button type="button" class="tb-linkbtn" wire:click="peekEntry({{ $entry->reverses_id }})">{{ $entry->reverses->entry_no }}</button>.</div>
      @endif
      @if($link = $this->entrySourceLink($entry))
        <div class="fn-note"><i class="fas fa-link" aria-hidden="true"></i> Posted from
          @if($link['url'])<x-ui.link :href="$link['url']">{{ $link['label'] }}</x-ui.link>@else{{ $link['label'] }}@endif
          — correct it there, and the books follow.</div>
      @endif

      <div class="tb-table-wrap tb-mt-3"><table class="tb-table">
        <thead><tr><th>Account</th><th>Note</th><th class="tb-text-right">Debit</th><th class="tb-text-right">Credit</th></tr></thead>
        <tbody>
          @foreach($entry->lines as $line)
            <tr wire:key="pl-{{ $line->id }}">
              <td>
                @can('finance.view')
                  <x-ui.link :href="route('admin.finance.accounts.show', $line->account)" class="tb-fw-500">{{ $line->account->label() }}</x-ui.link>
                @endcan
              </td>
              <td class="muted tb-small">{{ $line->memo }}</td>
              <td class="tb-text-right">@if((float) $line->debit > 0)<x-ui.money :amount="$line->debit" />@endif</td>
              <td class="tb-text-right">@if((float) $line->credit > 0)<x-ui.money :amount="$line->credit" />@endif</td>
            </tr>
          @endforeach
        </tbody>
        <tfoot><tr class="fn-total"><td colspan="2">Total</td><td class="tb-text-right"><x-ui.money :amount="$entry->total" /></td><td class="tb-text-right"><x-ui.money :amount="$entry->total" /></td></tr></tfoot>
      </table></div>

      @if($entry->canBeReversed())
        @can('finance.manage')
          <form wire:submit="reverseEntry" class="fn-reverse tb-mt-4">
            <x-ui.field label="Reverse this entry" for="rv-reason" name="reverseReason" hint="Writes the mirror image, dated today. Both stay in the journal.">
              <div class="tb-flex">
                <input id="rv-reason" type="text" wire:model="reverseReason" class="tb-input" placeholder="Why? e.g. Recorded twice" maxlength="200">
                <button type="submit" class="btn-tb btn-tb-danger" wire:loading.attr="disabled" wire:target="reverseEntry"
                        wire:confirm="Reverse {{ $entry->entry_no }}? This cannot be undone, only reversed again by a new entry.">
                  <i class="fas fa-rotate-left" aria-hidden="true"></i> Reverse
                </button>
              </div>
            </x-ui.field>
          </form>
        @endcan
      @endif
    </div>
    <div class="tb-modal-foot">
      <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Close</button>
    </div>
  @endif
</x-ui.modal>
