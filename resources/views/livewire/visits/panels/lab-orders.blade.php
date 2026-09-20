<div class="tb-card">
  <div class="tb-card-header">
    <span class="tb-card-title"><i class="fas fa-flask" aria-hidden="true"></i> Lab orders</span>
    @if($this->tests->isNotEmpty())
      <button type="button" class="btn-tb btn-tb-sm" wire:click="openOrder">
        <i class="fas fa-plus" aria-hidden="true"></i> Order tests
      </button>
    @endif
  </div>
  <div class="tb-card-body">

    @if($this->orders->isEmpty())
      <x-ui.empty compact icon="fa-flask" message="No lab orders on this visit." />
    @else
    <div class="tb-table-wrap">
      <table class="tb-table">
        <caption class="sr-only">Lab orders raised on this visit</caption>
        <thead><tr><th>Raised</th><th>Status</th><th>Tests</th></tr></thead>
        <tbody>
          @foreach($this->orders as $order)
            <tr wire:key="lo-{{ $order->id }}">
              <td class="tb-nowrap">
                <x-ui.link :href="route('admin.lab-orders.show', $order)">{{ $order->created_at->format('d M H:i') }}</x-ui.link>
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
  <x-ui.modal size="lg" show="showOrder" title="Order lab tests">
    @if($showOrder)
    <form wire:submit="order" style="display:contents;">
        <div class="tb-modal-body">
      <fieldset>
        <legend class="tb-label">Tests</legend>
        <div class="tb-flex">
          @forelse($this->tests as $test)
            <label class="tb-flex tb-small" wire:key="lt-{{ $test->id }}">
              <input type="checkbox" value="{{ $test->id }}" wire:model="test_ids">
              {{ $test->name }} <span class="muted"><x-ui.money :amount="$test->price" /></span>
            </label>
          @empty
            <span class="muted tb-small">No lab tests in the catalogue yet.</span>
          @endforelse
        </div>
        @error('test_ids')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
      </fieldset>

      <x-ui.field label="Clinical notes" for="lo-notes" name="clinical_notes" class="tb-mt-3">
        <input id="lo-notes" type="text" class="tb-input" maxlength="255" wire:model="clinical_notes">
      </x-ui.field>

        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="$set('showOrder', false)">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="order">
            <span wire:loading.remove wire:target="order"><i class="fas fa-flask" aria-hidden="true"></i> Order &amp; bill</span>
            <span wire:loading wire:target="order"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Ordering…</span>
          </button>
        </div>
    </form>
    @endif
  </x-ui.modal>
</div>
