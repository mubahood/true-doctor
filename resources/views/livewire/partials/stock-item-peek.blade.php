{{-- One stock item, over whatever list you came from.

     Everything the row leaves out, and the last few things that happened to
     it — which is the part somebody is actually asking about when they ask why
     there are only twelve left. Shared by the stock list and the alerts board
     so the two cannot come to show different things about one item. --}}
  {{-- ── The item, over the list ───────────────────────────────────
       Everything the row leaves out, and the last few things that
       happened to it — which is the part somebody is actually asking
       about when they ask why there are only twelve left. --}}
  <x-ui.modal show="showPeek" size="lg" autosaves :title="$this->peeked?->name ?? 'Stock item'">
    @if($this->peeked)
      @php($it = $this->peeked)
      <div class="tb-modal-body">
        <div class="tb-peek-head">
          <div>
            <div class="tb-peek-when">
              {{ rtrim(rtrim((string) $it->current_quantity, '0'), '.') }} {{ $it->unit }} on the shelf
            </div>
            <div class="tb-peek-time">
              Worth {{ \App\Support\HospitalSettings::money($it->current_stock_value) }} at cost
            </div>
          </div>
          @if($it->isExpired())
            <x-ui.badge tone="danger">Expired</x-ui.badge>
          @elseif($it->isLowStock())
            <x-ui.badge tone="warn">Running out</x-ui.badge>
          @elseif(! $it->is_active)
            <x-ui.badge tone="danger">Inactive</x-ui.badge>
          @else
            <x-ui.badge tone="success">In stock</x-ui.badge>
          @endif
        </div>

        <dl class="tb-peek-facts">
          <dt>Category</dt><dd>{{ $it->category?->name ?? '—' }}</dd>
          <dt>Counted in</dt><dd>{{ $it->unit }}</dd>
          <dt>Code</dt><dd class="mono">{{ $it->sku ?: '—' }}</dd>
          <dt>Reorder at</dt>
          <dd>
            {{ rtrim(rtrim((string) $it->reorder_level, '0'), '.') }} {{ $it->unit }}
            @if($it->isLowStock())<span class="tb-peek-bad">— at or below it now</span>@endif
          </dd>

          <dt>Costs</dt><dd>{{ \App\Support\HospitalSettings::money($it->cost_price) }} per {{ $it->unit }}</dd>
          <dt>Sells for</dt>
          <dd>
            {{ \App\Support\HospitalSettings::money($it->sale_price) }} per {{ $it->unit }}
            @php($unitMargin = bcsub((string) $it->sale_price, (string) $it->cost_price, 2))
            <span @class(['tb-peek-margin', 'is-bad' => bccomp($unitMargin, '0', 2) < 0])>
              {{ bccomp($unitMargin, '0', 2) < 0 ? 'losing' : 'margin' }}
              {{ \App\Support\HospitalSettings::money($unitMargin) }}
            </span>
          </dd>

          @if($it->batch_no)<dt>Batch</dt><dd class="mono">{{ $it->batch_no }}</dd>@endif
          @if($it->expiry_date)
            <dt>Expires</dt>
            <dd @class(['tb-peek-bad' => $it->isExpired()])>
              {{ $it->expiry_date->format('j M Y') }} · {{ $it->expiry_date->diffForHumans() }}
            </dd>
          @endif
        </dl>

        {{-- How it got to the number at the top. --}}
        <div class="tb-peek-sec">Last few movements</div>
        @if($this->peekedHistory->isNotEmpty())
          <ul class="tb-peek-trail">
            @foreach($this->peekedHistory as $m)
              <li wire:key="peek-mv-{{ $m->id }}">
                <span class="tb-peek-step">
                  <span @class(['tb-mv-qty', 'is-in' => $m->reason->isIncoming()])>
                    {{ $m->reason->isIncoming() ? '+' : '−' }}{{ rtrim(rtrim((string) $m->quantity, '0'), '.') }}
                  </span>
                  {{ $m->reason->label() }} · left {{ rtrim(rtrim((string) $m->balance_after, '0'), '.') }}
                </span>
                <span class="tb-peek-meta">
                  {{ $m->created_at?->format('j M · H:i') }}@if($m->createdBy) · {{ $m->createdBy->name }}@endif
                </span>
                @if($m->note)<span class="tb-peek-note">{{ $m->note }}</span>@endif
              </li>
            @endforeach
          </ul>
        @else
          <p class="tb-out-none">Nothing has moved on or off this shelf yet.</p>
        @endif
      </div>

      <div class="tb-modal-foot tb-peek-foot">
        <a class="btn-tb btn-tb-ghost" wire:navigate href="{{ route('admin.stock.movements', ['item' => $it->id]) }}">
          <i class="fas fa-book" aria-hidden="true"></i> Whole ledger
        </a>
        <span class="tb-peek-gap"></span>
        <button type="button" class="btn-tb btn-tb-ghost" wire:click="closePeek">Close</button>
        @can('update', $it)
          <button type="button" class="btn-tb" wire:click="openAdjust({{ $it->id }})"><i class="fas fa-sliders" aria-hidden="true"></i> Adjust</button>
          <button type="button" class="btn-tb btn-tb-primary" wire:click="openReceive({{ $it->id }})"><i class="fas fa-arrow-down" aria-hidden="true"></i> Receive</button>
        @endcan
      </div>
    @endif
  </x-ui.modal>

