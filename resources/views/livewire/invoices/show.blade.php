<div>
  <x-ui.page-header :title="$invoice->invoice_no"
    :crumbs="['Invoices' => route('admin.invoices.index'), $invoice->invoice_no => null]">
    <x-slot:subtitle>
      <div class="tb-flex tb-mt-2">
        <x-ui.badge :tone="$invoice->status->badge()">{{ $invoice->status->label() }}</x-ui.badge>
        @if($invoice->patient)
          <x-ui.link :href="route('admin.patients.show', $invoice->patient)" class="tb-small">{{ $invoice->patient->full_name }}</x-ui.link>
        @endif
        @if($invoice->issued_at)<span class="muted tb-small">Issued {{ $invoice->issued_at->format('d M Y') }}</span>@endif
      </div>
    </x-slot:subtitle>
    <x-slot:actions>
      {{-- Leaves the SPA on purpose: a generated document opens in its own
           tab rather than replacing the page (docs/documents.md). --}}
      <x-ui.link :href="route('admin.invoices.pdf', $invoice)" :navigate="false"
                 target="_blank" rel="noopener" class="btn-tb">
        <i class="fas fa-file-pdf" aria-hidden="true"></i> PDF
      </x-ui.link>
    </x-slot:actions>
  </x-ui.page-header>

  <div class="tb-detail-cols">
    {{-- ── Money rail ───────────────────────────────────────────── --}}
    <div class="tb-stack">
      @can('pay', $invoice)
        @if($invoice->status->isPayable())
          <div class="tb-card">
            <div class="tb-card-header"><span class="tb-card-title">Take payment</span></div>
            <div class="tb-card-body">
              <div class="tb-flex">
                <span class="muted tb-small">Outstanding</span>
                <x-ui.money :amount="$invoice->balance" class="tb-fw-600" />
              </div>
              <button type="button" wire:click="openPayment" class="btn-tb btn-tb-primary tb-mt-3">
                <i class="fas fa-cash-register" aria-hidden="true"></i> Record payment
              </button>

              {{-- Gateway redirect: must leave the SPA, so it stays a classic
                   POST — which means `wire:loading` cannot guard it. The button
                   disables itself ON submit instead, after the form data is
                   collected: a second click here is a second payment. --}}
              <form method="POST" action="{{ route('admin.invoices.flutterwave', $invoice) }}" class="tb-mt-3"
                    x-data
                    x-on:submit="$el.querySelector('button[type=submit]').setAttribute('disabled', 'disabled')">@csrf
                <button type="submit" class="btn-tb" wire:loading.attr="disabled">
                  <i class="fas fa-globe" aria-hidden="true"></i> Pay online (Flutterwave)
                </button>
                <p class="muted tb-xs tb-mt-2">Card · Mobile money · Bank · USSD</p>
              </form>
            </div>
          </div>
        @endif
      @endcan

      <div class="tb-card">
        <div class="tb-card-header"><span class="tb-card-title">Payments</span></div>
        <div class="tb-table-wrap"><table class="tb-table tb-small">
          <thead><tr><th>When</th><th>Method</th><th class="tb-text-right">Amount</th><th><span class="sr-only">Receipt</span></th></tr></thead>
          <tbody>
            @forelse($invoice->payments as $p)
              <tr wire:key="payment-{{ $p->id }}">
                <td class="muted">{{ $p->created_at?->format('d M H:i') }}</td>
                <td>{{ $p->method->label() }}</td>
                <td class="tb-text-right"><x-ui.money :amount="$p->amount" /></td>
                <td class="tb-text-right">
                  {{-- Opens in a tab: a receipt is read before it is filed. --}}
                  <x-ui.icon-button label="Receipt for {{ \App\Support\HospitalSettings::money($p->amount) }}"
                    icon="fa-receipt" :href="route('admin.payments.receipt', $p)" :navigate="false"
                    target="_blank" rel="noopener" />
                  @if($printer = app(\App\Support\HospitalSettings::class)->receiptPrinter())
                    <x-ui.icon-button label="Print on the receipt printer ({{ $printer }} mm)"
                      icon="fa-print" :href="route('admin.payments.receipt.thermal', [$p, 'w' => $printer])" :navigate="false"
                      target="_blank" rel="noopener" />
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="4"><x-ui.empty icon="fa-receipt" noun="payments" /></td></tr>
            @endforelse
          </tbody>
        </table></div>
      </div>
      {{-- ── Credit notes and refunds (docs/refunds.md) ─────────────── --}}
      @php($cf = $this->creditFigures)
      <div class="tb-card">
        <div class="tb-card-header">
          <span class="tb-card-title">Credit notes and refunds</span>
          @can('credit', $invoice)
            <div class="tb-flex">
              @if(bccomp($cf['creditable'], '0', 2) > 0)
                <button type="button" class="btn-tb btn-tb-sm" wire:click="openCredit('credit_note')">
                  <i class="fas fa-file-circle-minus" aria-hidden="true"></i> Credit note
                </button>
              @endif
              @if(bccomp($cf['can_refund'], '0', 2) > 0)
                <button type="button" class="btn-tb btn-tb-sm" wire:click="openCredit('refund')">
                  <i class="fas fa-money-bill-transfer" aria-hidden="true"></i> Refund
                </button>
              @endif
            </div>
          @endcan
        </div>
        <div class="tb-table-wrap"><table class="tb-table tb-small">
          <thead><tr><th>Document</th><th class="tb-text-right">Amount</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
          <tbody>
            @forelse($this->credits as $c)
              <tr wire:key="credit-{{ $c->id }}">
                <td>
                  <span class="tb-fw-500">{{ $c->kind->label() }}</span> <span class="mono muted">{{ $c->label() }}</span>
                  <div class="muted tb-xs">{{ $c->reason }}@if($c->method) · {{ $c->method->label() }}@endif · {{ $c->requestedBy?->name ?? '—' }}</div>
                  @if($c->decision_note)<div class="muted tb-xs">Rejected: {{ $c->decision_note }}</div>@endif
                </td>
                <td class="tb-text-right"><x-ui.money :amount="$c->amount" /></td>
                <td><x-ui.badge :tone="$c->status->badge()">{{ $c->status->label() }}</x-ui.badge></td>
                <td class="tb-text-right tb-nowrap">
                  @if($c->number)
                    <x-ui.icon-button label="Printable {{ $c->number }}" icon="fa-file-pdf"
                      :href="route('admin.credits.pdf', $c)" :navigate="false" target="_blank" rel="noopener" />
                  @elseif($c->isPending())
                    @can('approve', $c)
                      <x-ui.icon-button label="Approve {{ strtolower($c->kind->label()) }} of {{ \App\Support\HospitalSettings::money($c->amount) }}" icon="fa-check"
                        wire:click="approveCredit({{ $c->id }})" wire:loading.attr="disabled" wire:target="approveCredit"
                        wire:confirm="Approve this {{ strtolower($c->kind->label()) }}? It goes on the invoice and into the books." />
                      <x-ui.icon-button label="Reject" icon="fa-xmark" wire:click="openReject({{ $c->id }})" />
                    @endcan
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="4"><x-ui.empty icon="fa-file-circle-minus" noun="credit notes or refunds" /></td></tr>
            @endforelse
          </tbody>
        </table></div>
        @if(bccomp($cf['refundable'], '0', 2) > 0)
          <div class="tb-card-footer muted tb-xs">Paid beyond what the invoice now asks for: <x-ui.money :amount="$cf['refundable']" />.</div>
        @endif
      </div>
    </div>

    {{-- ── Invoice lines ────────────────────────────────────────── --}}
    <div class="tb-card">
      <div class="tb-card-header"><span class="tb-card-title">Invoice lines</span></div>
      <div class="tb-table-wrap"><table class="tb-table">
        <thead><tr><th>Description</th><th class="tb-text-center">Qty</th><th class="tb-text-right">Unit</th><th class="tb-text-right">Total</th></tr></thead>
        <tbody>
          @forelse($invoice->items as $it)
            <tr wire:key="line-{{ $it->id }}">
              <td class="tb-fw-500">{{ $it->description }}</td>
              <td class="tb-text-center">{{ $it->quantity }}</td>
              <td class="tb-text-right"><x-ui.money :amount="$it->unit_price" /></td>
              <td class="tb-text-right"><x-ui.money :amount="$it->line_total" /></td>
            </tr>
          @empty
            <tr><td colspan="4"><x-ui.empty icon="fa-file-invoice-dollar" noun="lines" /></td></tr>
          @endforelse
        </tbody>
        <tfoot>
          <tr><th colspan="3" class="tb-text-right">Subtotal</th><th class="tb-text-right"><x-ui.money :amount="$invoice->subtotal" /></th></tr>
          @if(bccomp((string) $invoice->tax_total, '0', 2) > 0)
            <tr><th colspan="3" class="tb-text-right">{{ app(\App\Support\HospitalSettings::class)->taxLabel() }}</th><th class="tb-text-right"><x-ui.money :amount="$invoice->tax_total" /></th></tr>
          @endif
          @if(bccomp((string) $invoice->discount, '0', 2) > 0)
            <tr><th colspan="3" class="tb-text-right">Discount</th><th class="tb-text-right">− <x-ui.money :amount="$invoice->discount" /></th></tr>
          @endif
          <tr><th colspan="3" class="tb-text-right">Total</th><th class="tb-text-right"><x-ui.money :amount="$invoice->total" /></th></tr>
          @if(bccomp((string) $invoice->credited, '0', 2) > 0)
            <tr><th colspan="3" class="tb-text-right">Credit notes</th><th class="tb-text-right">− <x-ui.money :amount="$invoice->credited" /></th></tr>
          @endif
          <tr><th colspan="3" class="tb-text-right">Paid</th><th class="tb-text-right"><x-ui.money :amount="$invoice->amount_paid" /></th></tr>
          @if(bccomp((string) $invoice->refunded, '0', 2) > 0)
            <tr><th colspan="3" class="tb-text-right">Refunded</th><th class="tb-text-right"><x-ui.money :amount="$invoice->refunded" /></th></tr>
          @endif
          <tr><th colspan="3" class="tb-text-right tb-primary">Balance</th><th class="tb-text-right tb-primary"><x-ui.money :amount="$invoice->balance" /></th></tr>
        </tfoot>
      </table></div>
    </div>
  </div>

  {{-- ── Slide-over: take payment (pessimistic) ─────────────────── --}}
  <x-ui.modal size="md" show="showPayment" title="Take payment">
    @if($showPayment)
      <form wire:submit="recordPayment" style="display:contents;">
        <div class="tb-modal-body">
          <div class="tb-panel">
            <div class="tb-flex">
              <span class="muted tb-small">{{ $invoice->invoice_no }} · outstanding</span>
              <x-ui.money :amount="$invoice->balance" class="tb-fw-600" />
            </div>
          </div>

          <x-ui.field label="Method" for="pay-method" name="method" required>
            <select id="pay-method" wire:model.live="method" class="tb-select">
              @foreach($methods as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
            </select>
          </x-ui.field>

          @if($method === \App\Enums\PaymentMethod::Card->value)
            <x-ui.field label="Prepaid card" for="pay-card" name="card_uuid" required>
              <select id="pay-card" wire:model="card_uuid" class="tb-select">
                <option value="">— select card —</option>
                @foreach($this->cards as $card)
                  <option value="{{ $card->uuid }}">{{ $card->masked() }} · {{ \App\Support\HospitalSettings::money($card->balance) }}</option>
                @endforeach
              </select>
            </x-ui.field>
          @endif

          <x-ui.field label="Amount" for="pay-amount" name="amount" required>
            <input id="pay-amount" type="number" step="0.01" min="0.01" wire:model="amount" class="tb-input" required>
          </x-ui.field>

          <x-ui.field label="Reference / note" for="pay-ref" name="reference" hint="Receipt book number, transaction id, or a short note.">
            <input id="pay-ref" type="text" wire:model="reference" class="tb-input" maxlength="120">
          </x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="recordPayment">
            <span wire:loading.remove wire:target="recordPayment"><i class="fas fa-check" aria-hidden="true"></i> Record payment</span>
            <span wire:loading wire:target="recordPayment"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Posting…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>
  {{-- ── Credit note / refund (pessimistic, like a payment) ──────── --}}
  <x-ui.modal size="md" show="showCredit" :title="$credit['kind'] === 'refund' ? 'Refund' : 'Credit note'">
    @if($showCredit)
      <form wire:submit="saveCredit" class="credit-form">
        <div class="tb-modal-body">
          <div class="tb-panel">
            <div class="tb-flex">
              @if($credit['kind'] === 'refund')
                <span class="muted tb-small">{{ $invoice->invoice_no }} · can be refunded</span>
                <x-ui.money :amount="$cf['can_refund']" class="tb-fw-600" />
              @else
                <span class="muted tb-small">{{ $invoice->invoice_no }} · can be credited</span>
                <x-ui.money :amount="$cf['creditable']" class="tb-fw-600" />
              @endif
            </div>
          </div>

          <x-ui.field label="Amount" for="credit-amount" name="credit.amount" required>
            <input id="credit-amount" type="number" step="0.01" min="0.01" wire:model="credit.amount" class="tb-input" required>
          </x-ui.field>

          @if($credit['kind'] === 'refund')
            <x-ui.field label="Paid back as" for="credit-method" name="credit.method" required>
              <select id="credit-method" wire:model.live="credit.method" class="tb-select">
                @foreach($refundMethods as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
              </select>
            </x-ui.field>
            @if($credit['method'] === \App\Enums\PaymentMethod::Card->value)
              <x-ui.field label="Prepaid card" for="credit-card" name="credit.card_uuid" required>
                <select id="credit-card" wire:model="credit.card_uuid" class="tb-select">
                  <option value="">— select card —</option>
                  @foreach($this->cards as $card)
                    <option value="{{ $card->uuid }}">{{ $card->masked() }} · {{ \App\Support\HospitalSettings::money($card->balance) }}</option>
                  @endforeach
                </select>
              </x-ui.field>
            @endif
            <x-ui.field label="Reference" for="credit-ref" name="credit.reference" hint="Transaction id for mobile money or a transfer.">
              <input id="credit-ref" type="text" wire:model="credit.reference" class="tb-input" maxlength="120">
            </x-ui.field>
          @endif

          <x-ui.field label="Reason" for="credit-reason" name="credit.reason" required hint="Printed on the document and kept in the books.">
            <input id="credit-reason" type="text" wire:model="credit.reason" class="tb-input" maxlength="500" required>
          </x-ui.field>

          @cannot('billing.refund')
            <p class="muted tb-xs">This goes to someone who approves refunds before it counts.</p>
          @endcannot
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="saveCredit">
            <span wire:loading.remove wire:target="saveCredit"><i class="fas fa-check" aria-hidden="true"></i> Save</span>
            <span wire:loading wire:target="saveCredit"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>

  <x-ui.modal size="sm" show="showReject" title="Reject">
    @if($showReject)
      <form wire:submit="rejectCredit" class="credit-form">
        <div class="tb-modal-body">
          <x-ui.field label="Why" for="reject-note" name="rejectNote" required>
            <input id="reject-note" type="text" wire:model="rejectNote" class="tb-input" maxlength="500" required>
          </x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="rejectCredit">
            <span wire:loading.remove wire:target="rejectCredit"><i class="fas fa-xmark" aria-hidden="true"></i> Reject</span>
            <span wire:loading wire:target="rejectCredit"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
