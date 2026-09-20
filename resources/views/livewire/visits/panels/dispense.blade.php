<div class="tb-card">
  <div class="tb-card-header">
    <span class="tb-card-title"><i class="fas fa-pills" aria-hidden="true"></i> Dispensing</span>
    <button type="button" class="btn-tb btn-tb-sm" wire:click="openForm">
      <i class="fas fa-plus" aria-hidden="true"></i> Dispense drugs
    </button>
  </div>
  <div class="tb-card-body">

    @if($this->dispensations->isEmpty())
      <x-ui.empty compact icon="fa-pills" message="Nothing has been dispensed on this visit." />
    @else
    <div class="tb-table-wrap">
      <table class="tb-table">
        <caption class="sr-only">Drugs dispensed on this visit</caption>
        <thead><tr><th>When</th><th>Items</th></tr></thead>
        <tbody>
          @foreach($this->dispensations as $dispensation)
            <tr wire:key="disp-{{ $dispensation->id }}">
              <td class="tb-nowrap">
                <x-ui.link :href="route('admin.dispensations.show', $dispensation)">{{ $dispensation->created_at->format('d M H:i') }}</x-ui.link>
              </td>
              <td class="muted">
                {{ $dispensation->items->map(fn ($item) => $item->name.' ×'.rtrim(rtrim((string) $item->quantity, '0'), '.'))->implode(', ') }}
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @endif
  </div>

  {{-- ── Dispensing ──────────────────────────────────────────────── --}}
  <x-ui.modal size="lg" show="showForm" title="Dispense drugs">
    @if($showForm)
    <form wire:submit="dispense" style="display:contents;">
        <div class="tb-modal-body">
      @error('items')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror

      @foreach($items as $index => $row)
        <div class="tb-inline-form tb-mb-4" wire:key="disp-row-{{ $row['uid'] }}">
          <x-ui.field label="Drug (from stock)" :name="'items.'.$index.'.stock_item_id'">
            <livewire:ui.select-search resource="stock-items"
                                       :name="'dispense-'.$row['uid']"
                                       :selected="$row['stock_item_id']"
                                       placeholder="Search stock items…"
                                       :key="'disp-pick-'.$row['uid']" />
          </x-ui.field>

          <x-ui.field label="Qty" :for="'disp-qty-'.$index" :name="'items.'.$index.'.quantity'">
            <input id="disp-qty-{{ $index }}" type="number" step="any" min="0.01" class="tb-input"
                   wire:model="items.{{ $index }}.quantity">
          </x-ui.field>

          @if(count($items) > 1)
            <x-ui.icon-button label="Remove line {{ $index + 1 }}" icon="fa-trash" variant="danger"
                              wire:click="removeRow({{ $index }})" />
          @endif
        </div>
      @endforeach

      <x-ui.field label="Note" for="disp-note" name="note">
        <input id="disp-note" type="text" class="tb-input" maxlength="255" wire:model="note">
      </x-ui.field>
        <button type="button" class="btn-tb btn-tb-sm" wire:click="addRow">
            <i class="fas fa-plus" aria-hidden="true"></i> Add another line
          </button>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="$set('showForm', false)">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="dispense">
            <span wire:loading.remove wire:target="dispense"><i class="fas fa-pills" aria-hidden="true"></i> Dispense &amp; bill</span>
            <span wire:loading wire:target="dispense"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Dispensing…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
