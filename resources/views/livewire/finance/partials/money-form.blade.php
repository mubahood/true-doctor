{{-- Money in / money out / transfer (App\Livewire\Concerns\RecordsMoney). --}}
<x-ui.modal show="showMoney" size="md" :title="match ($moneyKind) { 'out' => 'Money out', 'transfer' => 'Move money between books', default => 'Money in' }">
  @if($showMoney)
    <form wire:submit="saveMoney" style="display:contents;">
      <div class="tb-modal-body">
        <div class="fn-kind" role="tablist" aria-label="Kind of entry">
          @foreach(['in' => ['Money in', 'fa-arrow-down'], 'out' => ['Money out', 'fa-arrow-up'], 'transfer' => ['Transfer', 'fa-right-left']] as $k => [$label, $icon])
            <button type="button" role="tab" aria-selected="{{ $moneyKind === $k ? 'true' : 'false' }}"
                    @class(['fn-kind-btn', 'is-on' => $moneyKind === $k, 'is-'.$k]) wire:click="$set('moneyKind', '{{ $k }}')">
              <i class="fas {{ $icon }}" aria-hidden="true"></i> {{ $label }}
            </button>
          @endforeach
        </div>

        @if($this->moneyBooks->isEmpty())
          <p class="tb-field-error">You have no money books yet — add a cash, bank or mobile-money account in the chart of accounts.</p>
        @endif

        <div class="tb-form-grid">
          <x-ui.field label="Date" for="mn-date" name="moneyDate" required>
            <input id="mn-date" type="date" wire:model="moneyDate" class="tb-input" required>
          </x-ui.field>
          <x-ui.field label="Amount" for="mn-amount" name="moneyAmount" required>
            <input id="mn-amount" type="number" step="0.01" min="0.01" wire:model="moneyAmount" class="tb-input" required autofocus>
          </x-ui.field>
        </div>

        @if($moneyKind === 'transfer')
          <div class="tb-form-grid">
            <x-ui.field label="From" for="mn-from" name="moneyFromBook" required>
              <select id="mn-from" wire:model="moneyFromBook" class="tb-input" required>
                @foreach($this->moneyBooks as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
              </select>
            </x-ui.field>
            <x-ui.field label="To" for="mn-to" name="moneyToBook" required>
              <select id="mn-to" wire:model="moneyToBook" class="tb-input" required>
                <option value="">Choose…</option>
                @foreach($this->moneyBooks as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
              </select>
            </x-ui.field>
          </div>
          <x-ui.field label="Note" for="mn-memo" name="moneyMemo" hint="e.g. Day's takings banked">
            <input id="mn-memo" type="text" wire:model="moneyMemo" class="tb-input" maxlength="250">
          </x-ui.field>
        @else
          <div class="tb-form-grid">
            <x-ui.field :label="$moneyKind === 'out' ? 'Paid from' : 'Received into'" for="mn-book" name="moneyBook" required>
              <select id="mn-book" wire:model="moneyBook" class="tb-input" required>
                @foreach($this->moneyBooks as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
              </select>
            </x-ui.field>
            <x-ui.field :label="$moneyKind === 'out' ? 'What it was for' : 'What it was'" for="mn-cat" name="moneyAccount" required>
              <select id="mn-cat" wire:model="moneyAccount" class="tb-input" required>
                <option value="">Choose a category…</option>
                @foreach($this->moneyCategories as $group => $options)
                  <optgroup label="{{ $group }}">
                    @foreach($options as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                  </optgroup>
                @endforeach
              </select>
            </x-ui.field>
          </div>
          <x-ui.field label="Description" for="mn-memo" name="moneyMemo" required
                      :hint="$moneyKind === 'out' ? 'e.g. October electricity bill' : 'e.g. Donation towards the maternity ward'">
            <input id="mn-memo" type="text" wire:model="moneyMemo" class="tb-input" maxlength="250" required>
          </x-ui.field>
          <div class="tb-form-grid">
            <x-ui.field :label="$moneyKind === 'out' ? 'Paid to' : 'Received from'" for="mn-party" name="moneyParty">
              <input id="mn-party" type="text" wire:model="moneyParty" class="tb-input" maxlength="120" placeholder="Optional">
            </x-ui.field>
            <x-ui.field label="Reference" for="mn-ref" name="moneyReference" hint="Receipt, cheque or transaction number">
              <input id="mn-ref" type="text" wire:model="moneyReference" class="tb-input" maxlength="120">
            </x-ui.field>
          </div>
        @endif
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="saveMoney">
          <span wire:loading.remove wire:target="saveMoney"><i class="fas fa-check" aria-hidden="true"></i> Record</span>
          <span wire:loading wire:target="saveMoney"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Recording…</span>
        </button>
      </div>
    </form>
  @endif
</x-ui.modal>
