<div wire:poll.60s.visible>
  <x-ui.page-header title="Stock alerts" :crumbs="['Stock' => route('admin.stock.index'), 'Alerts' => null]">
    <x-slot:explain>
      Four different problems, worst first. Nothing left at all stops work today;
      expired stock is a loss already taken and still counted; running out is
      something to order; expiring soon is something to use first. Every row acts
      where it is, and the board refreshes itself every minute.
    </x-slot:explain>
    <x-slot:subtitle>
      <div class="tb-flex tb-mt-2">
        <span class="muted tb-small">As of {{ now()->format('H:i') }}</span>
        <span class="muted tb-small" wire:loading><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Refreshing…</span>
      </div>
    </x-slot:subtitle>
    <x-slot:actions>
      <x-ui.link :href="route('admin.stock.movements')" class="btn-tb"><i class="fas fa-book" aria-hidden="true"></i> Ledger</x-ui.link>
      <x-ui.link :href="route('admin.stock.index')" class="btn-tb"><i class="fas fa-boxes-stacked" aria-hidden="true"></i> Stock</x-ui.link>
    </x-slot:actions>
  </x-ui.page-header>

  @php($atRisk = $this->atRisk)

  {{-- Two of these four are money: what expired stock is WORTH, and what it
       would cost to put the shortages right. Counts alone never made anybody
       act. --}}
  <div class="tb-stats-grid tb-mb-4">
    <x-dash.stat icon="fa-ban" :tone="$atRisk['out'] > 0 ? 'bad' : 'ok'"
                 :value="$atRisk['out']" label="Out of stock"
                 :sub="$atRisk['out'] > 0 ? 'Cannot be dispensed at all' : 'Everything is dispensable'" />
    <x-dash.stat icon="fa-arrow-trend-down" :tone="$atRisk['low'] > 0 ? 'warn' : 'ok'"
                 :value="$atRisk['low']" label="Running out"
                 :sub="bccomp($atRisk['shortfall'], '0', 2) > 0
                     ? \App\Support\HospitalSettings::money($atRisk['shortfall']).' to restock'
                     : 'Nothing below its reorder level'" />
    <x-dash.stat icon="fa-skull-crossbones" :tone="$atRisk['expired'] > 0 ? 'bad' : 'ok'"
                 :value="$atRisk['expired']" label="Already expired"
                 :sub="$atRisk['expired'] > 0
                     ? \App\Support\HospitalSettings::money($atRisk['value']).' still on the books'
                     : 'Nothing out of date'" />
    <x-dash.stat icon="fa-clock" :tone="$atRisk['expiring'] > 0 ? 'warn' : 'ok'"
                 :value="$atRisk['expiring']" label="Expiring soon"
                 :sub="'Within '.$horizonDays.' days — use it first'" />
  </div>

  <div class="tb-filter-bar tb-toolbar">
    <select wire:model.live="category" class="tb-select" aria-label="Filter by category">
      <option value="">Every shelf</option>
      @foreach($this->categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
    </select>

    @if($this->pinnedCategory)
      <button type="button" class="tb-chip" wire:click="clearFilters"><i class="fas fa-xmark" aria-hidden="true"></i> Every shelf</button>
    @endif

    <div wire:loading.flex wire:target="category,clearFilters" class="muted tb-small tb-flex"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>
  </div>

  {{-- Nothing wrong anywhere: say so once, rather than four empty tables. --}}
  @if($atRisk['out'] === 0 && $atRisk['low'] === 0 && $atRisk['expired'] === 0 && $atRisk['expiring'] === 0)
    <div class="tb-card">
      <x-ui.empty icon="fa-circle-check"
        :message="$this->pinnedCategory
            ? 'Nothing needs attention on the '.$this->pinnedCategory->name.' shelf.'
            : 'Nothing needs attention. Every shelf is stocked and in date.'" />
    </div>
  @endif

  {{-- 1. Work has stopped. --}}
  @include('livewire.stock.partials.alert-lane', [
      'items' => $this->outOfStock,
      'title' => 'Out of stock',
      'note' => 'Nothing left. These cannot be dispensed at all.',
      'tone' => 'bad',
      'columns' => 'low',
      'sweepable' => false,
  ])

  {{-- 2. A loss already taken, still counted as stock. --}}
  @if($this->expired->isNotEmpty())
    <div class="tb-sweepbar">
      <span>
        <b>{{ $this->expired->count() }}</b> expired {{ \Illuminate\Support\Str::plural('line', $this->expired->count()) }},
        worth <b>{{ \App\Support\HospitalSettings::money($atRisk['value']) }}</b> and still on the books.
      </span>
      @can('update', $this->expired->first())
        <span class="tb-sweepbar-acts">
          @if($sweeping)
            <span class="muted tb-small">{{ count($sweeping) }} picked · {{ \App\Support\HospitalSettings::money($this->sweepValue()) }}</span>
            <button type="button" class="btn-tb btn-tb-danger btn-tb-sm" wire:click="openSweep">
              <i class="fas fa-trash" aria-hidden="true"></i> Write off the {{ count($sweeping) }}
            </button>
          @else
            {{-- A month's expiries is a sweep, not eleven separate decisions. --}}
            <button type="button" class="btn-tb btn-tb-sm" wire:click="sweepAll">
              <i class="fas fa-check-double" aria-hidden="true"></i> Pick all {{ $this->expired->count() }}
            </button>
          @endif
        </span>
      @endcan
    </div>
  @endif

  @include('livewire.stock.partials.alert-lane', [
      'items' => $this->expired,
      'title' => 'Already expired',
      'note' => 'Out of date and still counted. Write it off so the figures tell the truth.',
      'tone' => 'bad',
      'columns' => 'expiry',
      'sweepable' => true,
  ])

  {{-- 3. Something to order. --}}
  @include('livewire.stock.partials.alert-lane', [
      'items' => $this->lowStock,
      'title' => 'Running out',
      'note' => 'Below the reorder level. Receive against the row when a delivery arrives.',
      'tone' => 'warn',
      'columns' => 'low',
      'sweepable' => false,
  ])

  {{-- 4. Something to use first. --}}
  @include('livewire.stock.partials.alert-lane', [
      'items' => $this->expiring,
      'title' => 'Expiring soon',
      'note' => 'Within '.$horizonDays.' days and still usable. Anything inside '.\App\Livewire\Stock\Alerts::EXPIRY_URGENT_DAYS.' days is marked.',
      'tone' => 'warn',
      'columns' => 'expiry',
      'sweepable' => false,
  ])

  {{-- ── The sweep ─────────────────────────────────────────────────
       One reason, many lines — but every line still becomes its own
       ledger movement, with its own quantity and its own balance. --}}
  <x-ui.modal show="showSweep" size="md" title="Write off several at once">
    @if($showSweep)
      <form wire:submit="runSweep" style="display:contents;">
        <div class="tb-modal-body">
          <p class="tb-warnbar">
            <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
            <span>
              <b>{{ count($sweeping) }}</b> {{ \Illuminate\Support\Str::plural('line', count($sweeping)) }},
              worth <b>{{ \App\Support\HospitalSettings::money($this->sweepValue()) }}</b>, taken off the shelf for good.
              Each becomes its own entry in the ledger. It cannot be undone — only corrected with another movement.
            </span>
          </p>

          <ul class="tb-files">
            @foreach($this->expired as $it)
              @continue(! in_array($it->id, $sweeping, true))
              <li wire:key="sweep-{{ $it->id }}">
                <i class="fas fa-box tb-file-icon" aria-hidden="true"></i>
                <span class="tb-file-what">
                  {{ $it->name }}
                  <span class="tb-file-meta">
                    {{ rtrim(rtrim((string) $it->current_quantity, '0'), '.') }} {{ $it->unit }}
                    · {{ \App\Support\HospitalSettings::money($it->current_stock_value) }}
                    · expired {{ $it->expiry_date?->format('j M Y') }}
                  </span>
                </span>
                <x-ui.icon-button label="Take {{ $it->name }} out of the sweep" icon="fa-xmark"
                                  wire:click="toggleSweep({{ $it->id }})" />
              </li>
            @endforeach
          </ul>

          <x-ui.field label="What happened" for="sw-reason" name="sweepReason" required>
            <select id="sw-reason" wire:model="sweepReason" class="tb-select" required>
              @foreach($this->sweepReasons() as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
            </select>
          </x-ui.field>

          <x-ui.field label="Why" for="sw-note" name="sweepNote" required
                      hint="Goes on every one of these entries. This is the record somebody audits.">
            <input id="sw-note" type="text" wire:model="sweepNote" class="tb-input" maxlength="255">
          </x-ui.field>
        </div>

        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="closeSweep">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-danger" wire:loading.attr="disabled" wire:target="runSweep">
            <span wire:loading.remove wire:target="runSweep"><i class="fas fa-trash" aria-hidden="true"></i> Write off {{ count($sweeping) }}</span>
            <span wire:loading wire:target="runSweep"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Writing off…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>

  @include('livewire.partials.stock-item-peek')
  @include('livewire.partials.stock-move-dialog')
</div>
