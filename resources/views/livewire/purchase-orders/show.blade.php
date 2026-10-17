<div>
  @php
    $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';
    $figs = $this->figures;
    $status = $order->status;
    $canManage = auth()->user()->can('update', $order);
    $hasReceipts = $order->receipts->isNotEmpty();
  @endphp

  <x-ui.page-header :title="$order->po_no"
    :crumbs="['Purchase orders' => route('admin.purchase-orders.index'), $order->po_no => null]">
    <x-slot:subtitle>
      <div class="tb-flex tb-mt-2">
        <x-ui.badge :tone="$status->tone()">{{ $status->label() }}</x-ui.badge>
        <span class="tb-small">{{ $order->supplier?->name }}</span>
        <span class="muted tb-small">Dated {{ $order->order_date->format('j M Y') }}</span>
        @if($order->expected_date)<span class="muted tb-small">Expected {{ $order->expected_date->format('j M Y') }}</span>@endif
      </div>
    </x-slot:subtitle>
    <x-slot:actions>
      <a class="btn-tb" target="_blank" href="{{ route('admin.purchase-orders.pdf', $order) }}">
        <i class="fas fa-file-pdf" aria-hidden="true"></i> PDF
      </a>
      @if($canManage)
        @if($status === \App\Enums\PurchaseOrderStatus::Draft)
          <button type="button" class="btn-tb btn-tb-primary" wire:click="send" wire:loading.attr="disabled" wire:target="send"
                  @disabled($order->items->isEmpty())>
            <i class="fas fa-paper-plane" aria-hidden="true"></i> Mark as ordered
          </button>
        @endif
        @if($status->canReceive())
          <button type="button" class="btn-tb btn-tb-primary" wire:click="openReceive">
            <i class="fas fa-truck-ramp-box" aria-hidden="true"></i> Receive goods
          </button>
        @endif
        @if($status === \App\Enums\PurchaseOrderStatus::PartReceived)
          <button type="button" class="btn-tb" wire:click="openEnd('close')">
            <i class="fas fa-lock" aria-hidden="true"></i> Close
          </button>
        @endif
        @if($status->canTransitionTo(\App\Enums\PurchaseOrderStatus::Cancelled) && ! $hasReceipts)
          <button type="button" class="btn-tb btn-tb-danger" wire:click="openEnd('cancel')">
            <i class="fas fa-ban" aria-hidden="true"></i> Cancel
          </button>
        @endif
      @endif
    </x-slot:actions>
  </x-ui.page-header>

  @if($errors->hasAny(['status', 'reason']))
    <div class="tb-alert tb-alert-error tb-mb-3" role="alert">
      <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
      <span>{{ $errors->first('status') ?: $errors->first('reason') }}</span>
    </div>
  @endif

  <div class="tb-stats-grid tb-mb-3">
    <x-dash.stat icon="fa-file-invoice" :value="\App\Support\HospitalSettings::money($figs['ordered'])" label="Ordered" :sub="$order->items->count().' '.\Illuminate\Support\Str::plural('line', $order->items->count())" />
    <x-dash.stat icon="fa-truck-ramp-box" tone="ok" :value="\App\Support\HospitalSettings::money($figs['received'])" label="Received" :sub="$order->receipts->count().' '.\Illuminate\Support\Str::plural('delivery', $order->receipts->count())" />
    <x-dash.stat icon="fa-hourglass-half" :tone="bccomp($figs['outstanding'], '0', 2) > 0 ? 'warn' : ''" :value="\App\Support\HospitalSettings::money($figs['outstanding'])" label="Still to come" sub="at the ordered cost" />
    <x-dash.stat icon="fa-rotate-left" :tone="bccomp($figs['returned'], '0', 2) > 0 ? 'bad' : ''" :value="\App\Support\HospitalSettings::money($figs['returned'])" label="Returned" sub="to the supplier" />
  </div>

  @if($order->end_reason)
    <p class="tb-out-none tb-mb-3">
      {{ $status->label() }} {{ $order->ended_at?->format('j M Y') }}{{ $order->endedBy ? ' by '.$order->endedBy->name : '' }}: {{ $order->end_reason }}
    </p>
  @endif

  {{-- ── What is ordered ───────────────────────────────────────── --}}
  <div class="tb-card tb-mb-3">
    <div class="tb-card-header">
      <span class="tb-card-title">What is ordered</span>
      @if($order->notes)<span class="muted tb-xs">{{ $order->notes }}</span>@endif
    </div>

    @if($isDraft && $canManage)
      <form wire:submit="addLine" class="tb-card-body tb-inline-form">
        <x-ui.field label="Item" name="stock_item_id">
          <livewire:ui.select-search resource="stock-items" name="po_stock_item_id" :selected="$stock_item_id"
                                     placeholder="Search stock items…" :key="'po-item-'.$lineNonce" />
        </x-ui.field>
        <x-ui.field label="Quantity" for="po-line-qty" name="line_quantity">
          <input id="po-line-qty" type="number" step="0.01" min="0" wire:model="line_quantity" class="tb-input tb-proc-num">
        </x-ui.field>
        <x-ui.field label="Unit cost" for="po-line-cost" name="line_cost">
          <input id="po-line-cost" type="number" step="0.01" min="0" wire:model="line_cost" class="tb-input tb-proc-num">
        </x-ui.field>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="addLine">
          <i class="fas fa-plus" aria-hidden="true"></i> Add
        </button>
        @foreach(['line.stock_item_id', 'line.quantity', 'line.unit_cost'] as $key)
          @error($key)<span class="tb-danger tb-small">{{ $message }}</span>@enderror
        @endforeach
      </form>
    @endif

    <div class="tb-table-wrap">
      <table class="tb-table">
        <caption class="sr-only">Lines on {{ $order->po_no }}</caption>
        <thead><tr>
          <th class="tb-serial">#</th>
          <th>Item</th>
          <th class="tb-text-right">Ordered</th>
          <th class="tb-text-right">Received</th>
          <th class="tb-text-right">To come</th>
          <th class="tb-text-right">Unit cost</th>
          <th class="tb-text-right">Total</th>
          @if($isDraft && $canManage)<th><span class="sr-only">Actions</span></th>@endif
        </tr></thead>
        <tbody>
          @forelse($order->items as $line)
            <tr wire:key="po-line-{{ $line->id }}">
              <td class="tb-serial muted">{{ $loop->iteration }}</td>
              <td class="tb-fw-500">
                @if($line->stockItem)
                  <a wire:navigate href="{{ route('admin.stock.show', $line->stockItem) }}">{{ $line->stockItem->name }}</a>
                  <span class="tb-row-sub">{{ $line->stockItem->unit }}</span>
                @else
                  Stock item
                @endif
              </td>
              <td class="tb-text-right mono">{{ $qty($line->quantity) }}</td>
              <td class="tb-text-right mono">{{ $qty($line->received_quantity) }}</td>
              <td @class(['tb-text-right', 'mono', 'tb-warn' => bccomp($line->outstanding(), '0', 2) > 0 && $status->canReceive()])>{{ $status->isTerminal() ? '—' : $qty($line->outstanding()) }}</td>
              <td class="tb-text-right"><x-ui.money :amount="$line->unit_cost" /></td>
              <td class="tb-text-right"><x-ui.money :amount="$line->line_total" /></td>
              @if($isDraft && $canManage)
                <td class="tb-text-right">
                  <x-ui.icon-button label="Remove {{ $line->stockItem?->name }}" icon="fa-trash" wire:click="removeLine({{ $line->id }})" />
                </td>
              @endif
            </tr>
          @empty
            <tr><td colspan="8"><x-ui.empty icon="fa-boxes-stacked" noun="lines" /></td></tr>
          @endforelse
        </tbody>
        @if($order->items->isNotEmpty())
          <tfoot><tr>
            <th colspan="6" class="tb-text-right">Total</th>
            <th class="tb-text-right"><x-ui.money :amount="$order->total" /></th>
            @if($isDraft && $canManage)<th></th>@endif
          </tr></tfoot>
        @endif
      </table>
    </div>
  </div>

  {{-- ── Deliveries ────────────────────────────────────────────── --}}
  @foreach($order->receipts as $receipt)
    <div class="tb-card tb-mb-3" wire:key="grn-{{ $receipt->id }}">
      <div class="tb-card-header">
        <span class="tb-card-title">{{ $receipt->grn_no }} · received {{ $receipt->received_on->format('j M Y') }}</span>
        <span class="muted tb-xs">
          @if($receipt->supplier_invoice_no)Supplier invoice {{ $receipt->supplier_invoice_no }} · @endif
          {{ $receipt->receivedBy?->name ?? '' }} · <x-ui.money :amount="$receipt->total" />
        </span>
      </div>
      <div class="tb-table-wrap">
        <table class="tb-table tb-small">
          <caption class="sr-only">What came in on {{ $receipt->grn_no }}</caption>
          <thead><tr>
            <th>Item</th><th>Batch</th><th>Expiry</th>
            <th class="tb-text-right">Qty</th><th class="tb-text-right">Unit cost</th><th class="tb-text-right">Value</th>
            <th class="tb-text-right">Returned</th>
            @if($canManage)<th><span class="sr-only">Actions</span></th>@endif
          </tr></thead>
          <tbody>
            @foreach($receipt->items as $gl)
              @php($returned = $gl->returns->reduce(fn ($s, $r) => bcadd($s, (string) $r->quantity, 2), '0.00'))
              <tr wire:key="grn-line-{{ $gl->id }}">
                <td class="tb-fw-500">{{ $gl->stockItem?->name ?? 'Stock item' }}</td>
                <td class="muted">{{ $gl->batch_no ?? '—' }}</td>
                <td @class(['muted' => ! $gl->expiry_date?->isPast(), 'tb-danger' => $gl->expiry_date?->isPast()])>{{ $gl->expiry_date?->format('j M Y') ?? '—' }}</td>
                <td class="tb-text-right mono">{{ $qty($gl->quantity) }}</td>
                <td class="tb-text-right"><x-ui.money :amount="$gl->unit_cost" /></td>
                <td class="tb-text-right"><x-ui.money :amount="$gl->line_total" /></td>
                <td class="tb-text-right mono">{{ bccomp($returned, '0', 2) > 0 ? $qty($returned) : '—' }}</td>
                @if($canManage)
                  <td class="tb-text-right">
                    @if(bccomp($returned, (string) $gl->quantity, 2) < 0)
                      <button type="button" class="btn-tb btn-tb-sm" wire:click="openReturn({{ $gl->id }})">
                        <i class="fas fa-rotate-left" aria-hidden="true"></i> Return
                      </button>
                    @endif
                  </td>
                @endif
              </tr>
              @foreach($gl->returns as $ret)
                <tr wire:key="sr-{{ $ret->id }}" class="tb-proc-return">
                  <td colspan="3" class="muted">{{ $ret->return_no }} · {{ $ret->created_at?->format('j M Y') }} · {{ $ret->reason }}</td>
                  <td class="tb-text-right mono tb-danger">−{{ $qty($ret->quantity) }}</td>
                  <td></td>
                  <td class="tb-text-right tb-danger"><x-ui.money :amount="$ret->total" /></td>
                  <td colspan="{{ $canManage ? 2 : 1 }}"></td>
                </tr>
              @endforeach
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  @endforeach

  {{-- ── Receive goods ─────────────────────────────────────────── --}}
  <x-ui.modal show="showReceive" size="xl" title="Receive goods">
    @if($showReceive)
      <form wire:submit="receive" class="tb-proc-form">
        <div class="tb-modal-body">
          <p class="muted tb-small tb-mb-3">Enter what actually came. Anything left at 0 stays on order. The cost is what the supplier's invoice says; it becomes the item's cost.</p>
          @error('receiving')<div class="tb-alert tb-alert-error tb-mb-3" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i><span>{{ $message }}</span></div>@enderror
          <div class="tb-grid-2">
            <x-ui.field label="Received on" for="grn-date" name="received_on" required>
              <input id="grn-date" type="date" wire:model="received_on" class="tb-input" required>
            </x-ui.field>
            <x-ui.field label="Supplier invoice or delivery note" for="grn-inv" name="supplier_invoice_no">
              <input id="grn-inv" type="text" wire:model="supplier_invoice_no" class="tb-input">
            </x-ui.field>
          </div>
          <div class="tb-table-wrap tb-mb-3">
            <table class="tb-table tb-small">
              <caption class="sr-only">Lines still to come</caption>
              <thead><tr><th>Item</th><th class="tb-text-right">To come</th><th>Quantity</th><th>Unit cost</th><th>Batch</th><th>Expiry</th></tr></thead>
              <tbody>
                @foreach($order->items as $line)
                  @continue(! isset($receiving[$line->id]))
                  <tr wire:key="rcv-{{ $line->id }}">
                    <td class="tb-fw-500">{{ $line->stockItem?->name }}</td>
                    <td class="tb-text-right mono">{{ $qty($line->outstanding()) }}</td>
                    <td>
                      <input type="number" step="0.01" min="0" wire:model="receiving.{{ $line->id }}.quantity" class="tb-input tb-proc-num" aria-label="Quantity of {{ $line->stockItem?->name }}">
                      @error('receiving.'.$line->id.'.quantity')<span class="tb-danger tb-xs">{{ $message }}</span>@enderror
                    </td>
                    <td>
                      <input type="number" step="0.01" min="0" wire:model="receiving.{{ $line->id }}.unit_cost" class="tb-input tb-proc-num" aria-label="Unit cost of {{ $line->stockItem?->name }}">
                      @error('receiving.'.$line->id.'.unit_cost')<span class="tb-danger tb-xs">{{ $message }}</span>@enderror
                    </td>
                    <td><input type="text" wire:model="receiving.{{ $line->id }}.batch_no" class="tb-input" aria-label="Batch of {{ $line->stockItem?->name }}"></td>
                    <td>
                      <input type="date" wire:model="receiving.{{ $line->id }}.expiry_date" class="tb-input" aria-label="Expiry of {{ $line->stockItem?->name }}">
                      @error('receiving.'.$line->id.'.expiry_date')<span class="tb-danger tb-xs">{{ $message }}</span>@enderror
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
          <x-ui.field label="Notes" for="grn-notes" name="receipt_notes">
            <textarea id="grn-notes" wire:model="receipt_notes" class="tb-textarea" rows="2"></textarea>
          </x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="receive">
            <span wire:loading.remove wire:target="receive"><i class="fas fa-check" aria-hidden="true"></i> Put on the shelf</span>
            <span wire:loading wire:target="receive"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>

  {{-- ── Return to supplier ────────────────────────────────────── --}}
  <x-ui.modal show="showReturn" size="sm" title="Return to supplier">
    @if($showReturn)
      <form wire:submit="sendBack" class="tb-proc-form">
        <div class="tb-modal-body">
          <p class="muted tb-small tb-mb-3">It comes off the shelf and off what is owed to the supplier, at the delivery's cost.</p>
          <x-ui.field label="Quantity" for="sr-qty" name="return_quantity" required>
            <input id="sr-qty" type="number" step="0.01" min="0" wire:model="return_quantity" class="tb-input" required>
          </x-ui.field>
          <x-ui.field label="Why it is going back" for="sr-reason" name="return_reason" required>
            <input id="sr-reason" type="text" wire:model="return_reason" class="tb-input" required>
            <x-ui.suggestions set="return_reason" :current="$return_reason" :options="['Damaged', 'Wrong item', 'Short-dated', 'Expired on arrival', 'Not ordered']" />
          </x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-danger" wire:loading.attr="disabled" wire:target="sendBack">
            <i class="fas fa-rotate-left" aria-hidden="true"></i> Return
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>

  {{-- ── Close / cancel ────────────────────────────────────────── --}}
  <x-ui.modal show="showEnd" size="sm" :title="$endAs === 'close' ? 'Close the order' : 'Cancel the order'">
    @if($showEnd)
      <form wire:submit="end" class="tb-proc-form">
        <div class="tb-modal-body">
          <p class="muted tb-small tb-mb-3">
            {{ $endAs === 'close' ? 'What has come in stays. Nothing more will be received on this order.' : 'Nothing has been received, so nothing changes on the shelf or in the books.' }}
          </p>
          <x-ui.field label="Reason" for="po-end-reason" name="end_reason" required>
            <input id="po-end-reason" type="text" wire:model="end_reason" class="tb-input" required>
          </x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Back</button>
          <button type="submit" class="btn-tb btn-tb-danger" wire:loading.attr="disabled" wire:target="end">
            {{ $endAs === 'close' ? 'Close order' : 'Cancel order' }}
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
