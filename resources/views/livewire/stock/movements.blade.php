<div>
  <x-ui.page-header title="Stock ledger"
    :crumbs="['Stock' => route('admin.stock.index'), 'Ledger' => null]">
    <x-slot:explain>
      Everything that came in and everything that went out, in the order it happened.
      Each row shows what the shelf stood at afterwards, so the store can be
      reconstructed at any point. Nothing here can be edited — a ledger that can be
      rewritten is not one.
    </x-slot:explain>
    <x-slot:actions>
      <x-ui.link :href="route('admin.stock.index')" class="btn-tb"><i class="fas fa-boxes-stacked" aria-hidden="true"></i> Stock</x-ui.link>
    </x-slot:actions>
  </x-ui.page-header>

  @php($targets = 'search,direction,reason,item,from,to,gotoPage,previousPage,nextPage,perPage,sortBy,clearFilters')
  @php($totals = $this->totals)

  {{-- What the store spent, made and lost over these rows. Every movement
       carries the two prices it happened under, so a repricing today cannot
       rewrite what last month looked like. --}}
  <div class="tb-stats-grid tb-mb-4">
    <x-dash.stat icon="fa-cart-shopping"
                 :value="\App\Support\HospitalSettings::money($totals['bought'])"
                 label="Bought" :sub="rtrim(rtrim(number_format((float) $totals['in'], 2), '0'), '.').' units in'" />
    <x-dash.stat icon="fa-cash-register" tone="ok"
                 :value="\App\Support\HospitalSettings::money($totals['sold'])"
                 label="Sold" :sub="rtrim(rtrim(number_format((float) $totals['out'], 2), '0'), '.').' units out'" />
    <x-dash.stat icon="fa-arrow-trend-up"
                 :tone="bccomp($totals['margin'], '0', 2) < 0 ? 'bad' : 'ok'"
                 :value="\App\Support\HospitalSettings::money($totals['margin'])"
                 label="Profit on what sold" sub="Selling price less what it cost" />
    <x-dash.stat icon="fa-trash" :tone="bccomp($totals['lost'], '0', 2) > 0 ? 'bad' : ''"
                 :value="\App\Support\HospitalSettings::money($totals['lost'])"
                 label="Lost" sub="Expired, damaged or gone — at cost" />
  </div>

  {{-- The ledger stamped only the buying price, and only on the way in, until
       September 2026. Saying so beats quietly filling the gap with today's
       prices and calling the total a profit. --}}
  @if($totals['unpriced'] > 0)
    <div class="tb-warnbar">
      <i class="fas fa-circle-info" aria-hidden="true"></i>
      <span><b>{{ $totals['unpriced'] }}</b> of these {{ \Illuminate\Support\Str::plural('movement', $totals['unpriced']) }}
        {{ $totals['unpriced'] === 1 ? 'was' : 'were' }} recorded before prices were kept on the ledger,
        so {{ $totals['unpriced'] === 1 ? 'it is' : 'they are' }} left out of the figures above rather than guessed at.</span>
    </div>
  @endif

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input"
             placeholder="Item or reason given…" aria-label="Search the ledger">
    </div>

    <select wire:model.live="direction" class="tb-select" aria-label="Filter by direction">
      <option value="">In and out</option>
      <option value="in">In only</option>
      <option value="out">Out only</option>
    </select>

    <select wire:model.live="reason" class="tb-select" aria-label="Filter by reason">
      <option value="">Every reason</option>
      @foreach($this->reasons() as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
    </select>

    @if($this->pinnedItem)
      <button type="button" class="tb-chip is-on" wire:click="$set('item', '')">
        <i class="fas fa-box" aria-hidden="true"></i> {{ $this->pinnedItem->name }} <i class="fas fa-xmark" aria-hidden="true"></i>
      </button>
    @endif

    <input type="date" wire:model.live="from" class="tb-input" aria-label="From date">
    <input type="date" wire:model.live="to" class="tb-input" aria-label="To date">

    @if($this->hasFilters())
      <button type="button" class="tb-chip" wire:click="clearFilters"><i class="fas fa-xmark" aria-hidden="true"></i> Clear</button>
    @endif

    <div wire:loading.flex wire:target="{{ $targets }}" class="muted tb-small tb-flex"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="{{ $targets }}"><div class="tb-table-wrap">
    <table class="tb-table">
      <caption class="sr-only">Every stock movement</caption>
      <thead><tr>
        <th class="tb-serial">#</th>
        <x-ui.th-sort field="created_at" :sort-field="$sortField" :sort-dir="$sortDir">When</x-ui.th-sort>
        <th>Item</th>
        <x-ui.th-sort field="reason" :sort-field="$sortField" :sort-dir="$sortDir">Why</x-ui.th-sort>
        <x-ui.th-sort field="quantity" :sort-field="$sortField" :sort-dir="$sortDir" align="right">Amount</x-ui.th-sort>
        <th class="tb-text-right">Shelf after</th>
        <th class="tb-text-right">At cost</th>
        <th class="tb-text-right">Profit / loss</th>
        <th>By</th>
        <th>Note</th>
      </tr></thead>
      <tbody>
        @forelse($rows as $m)
          <tr wire:key="mv-{{ $m->id }}">
            <x-ui.serial :rows="$rows" :loop="$loop" />
            <td class="tb-nowrap">
              <span class="tb-when-day">{{ $m->created_at?->format('D j M') }}</span>
              <span class="tb-when-time mono">{{ $m->created_at?->format('H:i') }}</span>
            </td>

            <td class="tb-fw-500">
              @if($m->stockItem)
                <a class="rowlink" wire:navigate href="{{ route('admin.stock.show', $m->stockItem) }}">{{ $m->stockItem->name }}</a>
                <span class="tb-row-sub">{{ $m->stockItem->category?->name ?? '—' }}</span>
              @else — @endif
            </td>

            {{-- The direction is the colour, so a page of movements reads
                 without anybody working out which reasons mean "out". --}}
            <td class="tb-nowrap">
              <span @class(['tb-mv-why', 'is-in' => $m->reason->isIncoming()])>
                <i class="fas {{ $m->reason->isIncoming() ? 'fa-arrow-down' : 'fa-arrow-up' }}" aria-hidden="true"></i>
                {{ $m->reason->label() }}
              </span>
            </td>

            <td class="tb-text-right mono">
              <span @class(['tb-mv-qty', 'is-in' => $m->reason->isIncoming()])>
                {{ $m->reason->isIncoming() ? '+' : '−' }}{{ rtrim(rtrim((string) $m->quantity, '0'), '.') }}
              </span>
              <span class="tb-row-sub">{{ $m->stockItem?->unit }}</span>
            </td>

            {{-- What the shelf stood at after this row, which is what makes a
                 ledger a ledger rather than a list of events. --}}
            <td class="tb-text-right mono muted">{{ rtrim(rtrim((string) $m->balance_after, '0'), '.') }}</td>

            {{-- What it was worth, at the prices IT happened under. --}}
            <td class="tb-text-right mono muted">
              {{ $m->costValue() === null ? '—' : \App\Support\HospitalSettings::money($m->costValue()) }}
            </td>
            <td class="tb-text-right mono">
              @if($m->margin() !== null)
                <span @class(['tb-mv-qty', 'is-in' => bccomp($m->margin(), '0', 2) >= 0])>
                  {{ \App\Support\HospitalSettings::money($m->margin()) }}
                </span>
              @elseif($m->reason->isLoss() && $m->costValue() !== null)
                <span class="tb-mv-qty">−{{ \App\Support\HospitalSettings::money($m->costValue()) }}</span>
              @else
                <span class="muted">—</span>
              @endif
            </td>

            <td class="muted tb-small">{{ $m->createdBy?->name ?? 'The system' }}</td>

            <td class="muted tb-small">{{ $m->note ?: '—' }}</td>
          </tr>
        @empty
          <tr><td colspan="10">
            <x-ui.empty icon="fa-book" noun="stock movements" :filtered="$this->hasFilters()"
                        :message="! $this->hasFilters() ? 'Nothing has moved on or off a shelf yet.' : null"
                        wire:click="clearFilters" />
          </td></tr>
        @endforelse
      </tbody>
    </table>
  </div></div>
  {{ $rows->links(data: $this->paginationData()) }}
</div>
