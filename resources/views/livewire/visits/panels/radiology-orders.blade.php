<div class="tb-card">
  <div class="tb-card-header">
    <span class="tb-card-title"><i class="fas fa-x-ray" aria-hidden="true"></i> Radiology</span>
    @if($this->studies->isNotEmpty())
      <button type="button" class="btn-tb btn-tb-sm" wire:click="openOrder">
        <i class="fas fa-plus" aria-hidden="true"></i> Order imaging
      </button>
    @endif
  </div>
  <div class="tb-card-body">

    @if($this->orders->isEmpty())
      <x-ui.empty compact icon="fa-x-ray" message="No imaging orders on this visit." />
    @else
    <div class="tb-table-wrap">
      <table class="tb-table">
        <caption class="sr-only">Radiology orders raised on this visit</caption>
        <thead><tr><th>Raised</th><th>Status</th><th>Studies</th></tr></thead>
        <tbody>
          @foreach($this->orders as $order)
            <tr wire:key="ro-{{ $order->id }}">
              <td class="tb-nowrap">
                <x-ui.link :href="route('admin.radiology-orders.show', $order)">{{ $order->created_at->format('d M H:i') }}</x-ui.link>
              </td>
              <td><x-ui.badge :tone="$order->status->badge()">{{ $order->status->label() }}</x-ui.badge></td>
              <td class="muted">{{ $order->items->pluck('name')->implode(', ') }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @endif
  </div>

  {{-- ── Ordering happens here, not on the page ────────────────── --}}
  <x-ui.modal size="lg" show="showOrder" title="Order imaging">
    @if($showOrder)
    <form wire:submit="order" style="display:contents;">
        <div class="tb-modal-body">
      <fieldset>
        <legend class="tb-label">Studies</legend>
        <div class="tb-flex">
          @forelse($this->studies as $study)
            <label class="tb-flex tb-small" wire:key="rs-{{ $study->id }}">
              <input type="checkbox" value="{{ $study->id }}" wire:model="study_ids">
              {{ $study->name }} <span class="muted"><x-ui.money :amount="$study->price" /></span>
            </label>
          @empty
            <span class="muted tb-small">No radiology studies in the catalogue yet.</span>
          @endforelse
        </div>
        @error('study_ids')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
      </fieldset>

      <x-ui.field label="Clinical notes" for="ro-notes" name="clinical_notes" class="tb-mt-3">
        <input id="ro-notes" type="text" class="tb-input" maxlength="255" wire:model="clinical_notes">
      </x-ui.field>

        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="$set('showOrder', false)">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="order">
            <span wire:loading.remove wire:target="order"><i class="fas fa-x-ray" aria-hidden="true"></i> Order &amp; bill</span>
            <span wire:loading wire:target="order"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Ordering…</span>
          </button>
        </div>
    </form>
    @endif
  </x-ui.modal>
</div>
