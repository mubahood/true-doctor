{{-- Moving stock on or off a shelf.

     One dialog for three acts, because they are one act: the shelf changed,
     and the ledger has to say by how much and why
     (App\Livewire\Concerns\MovesStock). --}}
<x-ui.modal show="showMove" size="md"
            :title="match($moveKind) {
                'receive' => 'Receive stock',
                'writeoff' => 'Write off',
                default => 'Adjust the shelf',
            }">
  @if($this->moving)
    @php($item = $this->moving)
    <form wire:submit="saveMove" style="display:contents;">
      <div class="tb-modal-body">
        <p class="tb-ending-who">
          <b>{{ $item->name }}</b>
          @if($item->category) · {{ $item->category->name }} @endif
          · on the shelf now: <b class="mono">{{ rtrim(rtrim((string) $item->current_quantity, '0'), '.') }} {{ $item->unit }}</b>
          @if($item->expiry_date)
            · expires <span @class(['tb-danger' => $item->isExpired()])>{{ $item->expiry_date->format('j M Y') }}</span>
          @endif
        </p>

        @if($moveKind === 'writeoff')
          {{-- The whole remaining quantity by default: writing off half a
               shelf of expired stock and leaving the rest on the books is the
               mistake this exists to stop. --}}
          <p class="tb-warnbar">
            <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
            <span>This takes the stock off the shelf for good and records it as wastage.
              It cannot be undone — only corrected with another movement.</span>
          </p>
        @endif

        <div class="tb-form-grid">
          <x-ui.field label="How many" for="mv-qty" name="quantity" required>
            <input id="mv-qty" type="number" step="0.01" min="0.01"
                   wire:model.live.debounce.400ms="quantity" class="tb-input" required autofocus>
          </x-ui.field>

          @if($moveKind === 'writeoff')
            <x-ui.field label="What happened" for="mv-loss" name="reason" required>
              <select id="mv-loss" wire:model="reason" class="tb-select" required>
                @foreach($this->lossReasons() as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
              </select>
            </x-ui.field>
          @elseif($moveKind === 'adjust')
            {{-- Not "which way" but WHY: "adjustment (out), 400 tablets" is a
                 number nobody can act on, and expired, damaged and stolen are
                 three different problems with three different fixes. --}}
            <x-ui.field label="What happened" for="mv-reason" name="reason" required>
              <select id="mv-reason" wire:model.live="reason" class="tb-select" required>
                @foreach($this->adjustReasons() as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
              </select>
            </x-ui.field>
          @endif
        </div>

        @if($moveKind === 'receive')
          {{-- A supplier's invoice says "20 boxes, 240,000". It does not say
               12,000 each, and asking somebody to divide before they can type
               is asking for an arithmetic mistake on the record. Either box
               fills the other. --}}
          <div class="tb-out-sec">
            <span>What it cost</span>
            <span class="tb-out-hint">Type whichever the delivery note gives you.</span>
          </div>

          <div class="tb-form-grid">
            <x-ui.field label="Total paid" for="mv-total" name="total_cost">
              <input id="mv-total" type="number" step="0.01" min="0"
                     wire:model.live.debounce.500ms="total_cost" class="tb-input">
            </x-ui.field>

            <x-ui.field label="Cost per {{ $item->unit }}" for="mv-cost" name="unit_cost"
                        hint="Leave empty to keep the price already on file.">
              <input id="mv-cost" type="number" step="0.01" min="0"
                     wire:model.live.debounce.500ms="unit_cost" class="tb-input">
            </x-ui.field>
          </div>

          <div class="tb-out-sec">
            <span>What it sells for</span>
            <span class="tb-out-hint">Every dispensation from this delivery is priced at this.</span>
          </div>

          <div class="tb-form-grid">
            <x-ui.field label="Price per {{ $item->unit }}" for="mv-sale" name="move_sale_price">
              <input id="mv-sale" type="number" step="0.01" min="0"
                     wire:model.live.debounce.500ms="move_sale_price" class="tb-input">
            </x-ui.field>
          </div>

          {{-- What the store stands to make, while it is being typed: a price
               entered the wrong way round is invisible between two boxes and
               obvious the moment this goes negative. --}}
          @php($preview = $this->marginPreview())
          @if($preview)
            <div @class(['tb-set-preview', 'is-bad' => bccomp($preview['margin'], '0', 2) < 0])>
              <div class="tb-set-row"><span>This delivery costs</span><b class="mono">{{ \App\Support\HospitalSettings::money($preview['cost']) }}</b></div>
              <div class="tb-set-row"><span>If it all sells</span><b class="mono">{{ \App\Support\HospitalSettings::money($preview['sale']) }}</b></div>
              <div class="tb-set-row is-total">
                <span>{{ bccomp($preview['margin'], '0', 2) < 0 ? 'Sold at a LOSS of' : 'Profit' }}</span>
                <b class="mono">{{ \App\Support\HospitalSettings::money($preview['margin']) }}@if($preview['percent'] !== null)
                  <span class="tb-margin-pct">{{ rtrim(rtrim($preview['percent'], '0'), '.') }}%</span>@endif</b>
              </div>
            </div>
          @endif
        @endif

        <x-ui.field label="{{ $moveKind === 'writeoff' ? 'Why' : 'Note' }}" for="mv-note" name="note"
                    :required="$moveKind === 'writeoff'"
                    hint="{{ $moveKind === 'writeoff'
                        ? 'This is the record somebody audits. Say what happened to it.'
                        : 'Optional — a delivery note number, who brought it, what was found.' }}">
          <input id="mv-note" type="text" wire:model="note" class="tb-input" maxlength="255">
          @if($moveKind === 'writeoff')
            <x-ui.suggestions set="note" :current="$note"
                              :options="['Expired', 'Damaged in storage', 'Broken in transit', 'Contaminated', 'Recalled by the supplier', 'Lost in a stock count']" />
          @elseif($moveKind === 'receive')
            <x-ui.suggestions set="note" :current="$note"
                              :options="['Delivery from supplier', 'Donation', 'Transfer from another store', 'Opening stock count']" />
          @endif
        </x-ui.field>
      </div>

      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" wire:click="closeMove">Cancel</button>
        <button type="submit" @class(['btn-tb', 'btn-tb-danger' => $moveKind === 'writeoff', 'btn-tb-primary' => $moveKind !== 'writeoff'])
                wire:loading.attr="disabled" wire:target="saveMove">
          <span wire:loading.remove wire:target="saveMove">
            <i class="fas {{ $moveKind === 'writeoff' ? 'fa-trash' : 'fa-check' }}" aria-hidden="true"></i>
            {{ match($moveKind) { 'receive' => 'Receive', 'writeoff' => 'Write it off', default => 'Record it' } }}
          </span>
          <span wire:loading wire:target="saveMove"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
        </button>
      </div>
    </form>
  @endif
</x-ui.modal>
