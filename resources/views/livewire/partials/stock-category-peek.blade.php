{{-- One shelf category, over the list.

     The counts in a row are already links to a filtered shelf, which is right.
     What is still missing is WHICH items are behind the numbers — the thing
     somebody clicking a red count is about to ask. --}}
<x-ui.modal show="showPeek" size="lg" autosaves :title="$this->peeked?->name ?? 'Category'">
  @if($this->peeked)
    @php($cat = $this->peeked)
    <div class="tb-modal-body">
      <x-ui.peek-head :heading="$cat->name" :sub="'Counted in '.$cat->unit">
        <x-ui.badge :tone="$cat->is_active ? 'active' : 'danger'">{{ $cat->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
      </x-ui.peek-head>

      <x-ui.peek-figs :figures="[
        ['label' => 'Items', 'value' => (string) $this->peekedItems->count(),
         'sub' => 'active on the shelf'],
        ['label' => 'Running out',
         'value' => (string) $this->peekedItems->filter(fn ($i) => $i->isLowStock())->count(),
         'bad' => $this->peekedItems->contains(fn ($i) => $i->isLowStock())],
        ['label' => 'Worth at cost',
         'value' => \App\Support\HospitalSettings::money(
           $this->peekedItems->reduce(fn ($sum, $i) => bcadd($sum, (string) $i->current_stock_value, 2), '0.00')
         )],
      ]" />

      @if($cat->description)
        <div class="tb-peek-sec">What belongs in it</div>
        <p class="tb-peek-note">{{ $cat->description }}</p>
      @endif

      {{-- Worst first: a category opened because its "running out" count was
           red should lead with the thing that is running out. --}}
      <x-ui.peek-trail title="What is on the shelf" :rows="$this->peekedItems"
                       empty="Nothing has been put in this category yet.">
        @foreach($this->peekedItems as $item)
          <li wire:key="cat-peek-item-{{ $item->id }}">
            <span class="tb-peek-step">
              <span @class(['tb-mv-qty', 'is-in' => ! $item->isLowStock()])>
                {{ rtrim(rtrim((string) $item->current_quantity, '0'), '.') ?: '0' }}
              </span>
              <span class="tb-fw-500">{{ $item->name }}</span>
              @if($item->isLowStock())<span class="tb-peek-bad">running out</span>@endif
              @if($item->isExpired())<span class="tb-peek-bad">expired</span>@endif
            </span>
            <span class="tb-peek-meta">
              {{ $item->unit }} · reorder at {{ rtrim(rtrim((string) $item->reorder_level, '0'), '.') ?: '0' }}
              @if($item->expiry_date) · expires {{ $item->expiry_date->format('j M Y') }} @endif
            </span>
          </li>
        @endforeach
      </x-ui.peek-trail>
    </div>

    <x-ui.peek-foot :href="route('admin.stock.index', ['category' => $cat->id])"
                    label="The whole shelf" icon="fa-boxes-stacked">
      @can('update', $cat)
        <button type="button" class="btn-tb btn-tb-ghost" wire:click="editPeeked">
          <i class="fas fa-pen" aria-hidden="true"></i> Edit category
        </button>
      @endcan
    </x-ui.peek-foot>
  @endif
</x-ui.modal>
