@props([
    'items',                 // Collection<StockItem>
    'title',
    'note',
    'tone' => '',            // '' | warn | bad
    'columns' => 'low',      // low | expiry
    'sweepable' => false,
])
{{--
  One lane of the alerts board.

  Four lanes, four problems, one shape — so a reader learns the table once and
  the difference between the lanes is the words at the top, not the layout.
--}}
@if($items->isNotEmpty())
  <div class="tb-lane">
    <div class="tb-lane-head">
      <span @class(['tb-lane-title', 'is-'.$tone => $tone])>{{ $title }}</span>
      <span class="tb-lane-n">{{ $items->count() }}</span>
      <span class="tb-lane-note">{{ $note }}</span>
      {{ $headActions ?? '' }}
    </div>

    <div class="tb-card"><div class="tb-table-wrap"><table class="tb-table">
      <caption class="sr-only">{{ $title }}</caption>
      <thead><tr>
        @if($sweepable)<th class="tb-serial"><span class="sr-only">Pick</span></th>@endif
        <th class="tb-serial">#</th>
        <th>Item</th>
        <th>Category</th>
        <th class="tb-text-right">On the shelf</th>
        @if($columns === 'low')
          <th class="tb-text-right">Reorder at</th>
          <th class="tb-text-right">Order</th>
          <th class="tb-text-right">Would cost</th>
        @else
          <th class="tb-text-right">Worth</th>
          <th>Batch</th>
          <th>Expiry</th>
        @endif
        <th><span class="sr-only">Actions</span></th>
      </tr></thead>
      <tbody>
        @foreach($items as $i => $it)
          <tr wire:key="alert-{{ $title }}-{{ $it->id }}">
            @if($sweepable)
              <td class="tb-serial">
                <input type="checkbox" wire:click="toggleSweep({{ $it->id }})"
                       @checked(in_array($it->id, $sweeping, true))
                       aria-label="Include {{ $it->name }} in the sweep">
              </td>
            @endif
            <td class="tb-serial mono">{{ $i + 1 }}</td>

            <td class="tb-fw-500">
              {{-- Opens the record over the board, like everywhere else. --}}
              <button type="button" class="tb-rowbtn" wire:click="peek({{ $it->id }})" title="See this item">{{ $it->name }}</button>
              @if($it->sku)<span class="tb-row-sub mono">{{ $it->sku }}</span>@endif
            </td>

            <td class="muted">{{ $it->category?->name ?? '—' }}</td>

            <td class="tb-text-right mono">
              <span @class(['tb-mv-qty', 'is-in' => bccomp((string) $it->current_quantity, '0', 2) > 0])>
                {{ rtrim(rtrim((string) $it->current_quantity, '0'), '.') }}
              </span>
              <span class="tb-row-sub">{{ $it->unit }}</span>
            </td>

            @if($columns === 'low')
              <td class="tb-text-right mono muted">{{ rtrim(rtrim((string) $it->reorder_level, '0'), '.') }}</td>
              {{-- How much would put it right, and what that costs: the two
                   things somebody raising an order needs and had to work out
                   in their head. --}}
              <td class="tb-text-right mono">{{ rtrim(rtrim($this->shortBy($it), '0'), '.') ?: '—' }}</td>
              <td class="tb-text-right mono muted">{{ \App\Support\HospitalSettings::money($this->shortfallValue($it)) }}</td>
            @else
              <td class="tb-text-right mono">{{ \App\Support\HospitalSettings::money($it->current_stock_value) }}</td>
              <td class="muted">{{ $it->batch_no ?: '—' }}</td>
              @php($days = $this->daysLeft($it))
              <td class="tb-nowrap">
                <span @class(['tb-exp-when', 'is-warn' => $this->expiryTone($it) === 'warn', 'is-bad' => $days !== null && $days < 0])>
                  {{ $it->expiry_date?->format('j M Y') }}
                </span>
                <span class="tb-row-sub">
                  @if($days === null) —
                  @elseif($days < 0) {{ abs($days) }} {{ \Illuminate\Support\Str::plural('day', abs($days)) }} ago
                  @elseif($days === 0) today
                  @else in {{ $days }} {{ \Illuminate\Support\Str::plural('day', $days) }}
                  @endif
                </span>
              </td>
            @endif

            <td class="tb-text-right tb-nowrap">
              @can('update', $it)
                @if($columns === 'low')
                  <button type="button" class="tb-nextbtn" wire:click="openReceive({{ $it->id }})"
                          title="Put stock on this shelf"><i class="fas fa-arrow-down" aria-hidden="true"></i> Receive</button>
                @else
                  <button type="button" class="tb-nextbtn is-bad" wire:click="openWriteOff({{ $it->id }})"
                          title="Take it off the shelf and record why"><i class="fas fa-trash" aria-hidden="true"></i> Write off</button>
                @endif
              @endcan

              <x-ui.actions-menu label="Actions for {{ $it->name }}">
                <button type="button" role="menuitem" wire:click="peek({{ $it->id }})"><i class="fas fa-eye" aria-hidden="true"></i> Quick view</button>
                <a role="menuitem" wire:navigate href="{{ route('admin.stock.show', $it) }}"><i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i> Full record</a>
                <a role="menuitem" wire:navigate href="{{ route('admin.stock.movements', ['item' => $it->id]) }}"><i class="fas fa-book" aria-hidden="true"></i> Everything that moved</a>
                @can('update', $it)
                  <hr>
                  <button type="button" role="menuitem" wire:click="openReceive({{ $it->id }})"><i class="fas fa-arrow-down" aria-hidden="true"></i> Receive</button>
                  <button type="button" role="menuitem" wire:click="openAdjust({{ $it->id }})"><i class="fas fa-sliders" aria-hidden="true"></i> Adjust</button>
                  <button type="button" role="menuitem" class="danger" wire:click="openWriteOff({{ $it->id }})"><i class="fas fa-trash" aria-hidden="true"></i> Write off</button>
                @endcan
              </x-ui.actions-menu>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table></div></div>
  </div>
@endif
