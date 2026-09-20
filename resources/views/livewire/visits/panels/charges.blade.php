<div class="tb-card">
  <div class="tb-card-header">
    <span class="tb-card-title"><i class="fas fa-file-invoice-dollar" aria-hidden="true"></i> Bill</span>
    <div class="tb-panel-acts">
      @can('billing.manage')
        @unless($this->invoice)
          {{-- Puts every live line back in step with its own unit price and
               quantity. Totals are stored so a reprice cannot move a charge
               already raised — this is the thing that notices if one drifts. --}}
          <x-ui.icon-button label="Check the bill adds up" icon="fa-rotate"
                            wire:click="rebill" wire:loading.attr="disabled" wire:target="rebill" />
          <button type="button" class="btn-tb btn-tb-sm" wire:click="openAdd">
            <i class="fas fa-plus" aria-hidden="true"></i> Add charge
          </button>
          <button type="button" class="btn-tb btn-tb-sm" wire:click="openDiscount">
            <i class="fas fa-tag" aria-hidden="true"></i>
            {{ bccomp($this->totals['discount'], '0', 2) > 0 ? 'Edit discount' : 'Add discount' }}
          </button>
        @endunless
      @endcan
    </div>
  </div>

  <div class="tb-card-body">
    @if($this->lines->isEmpty())
      <x-ui.empty compact icon="fa-file-invoice-dollar" message="Nothing has been charged to this visit." />
    @else
      @php($taxLabel = app(\App\Support\HospitalSettings::class)->taxLabel())
      <div class="tb-table-wrap">
        <table class="tb-table tb-bill-table">
          <caption class="sr-only">Billable lines on this visit</caption>
          <thead>
            <tr>
              <th>Charge</th>
              <th>Raised by</th>
              <th class="tb-text-right">Unit</th>
              <th class="tb-text-right">Qty</th>
              <th class="tb-text-right">Line total</th>
            </tr>
          </thead>
          <tbody>
            @foreach($this->lines as $line)
              @php($off = ! $line->status->isBillable())
              <tr wire:key="line-{{ $line->id }}" @class(['is-off' => $off])>
                <td>
                  {{ $line->name }}
                  @if($off)<span class="tb-ordm-gone">removed</span>@endif
                  {{-- Why the tax is what it is, on the line that decides it. --}}
                  @if($line->tax_exempt && bccomp($this->totals['tax'], '0', 2) > 0)
                    <span class="tb-line-exempt">no {{ strtolower($taxLabel) }}</span>
                  @endif
                  @if($line->notes)<span class="tb-line-note">{{ $line->notes }}</span>@endif
                </td>

                {{-- The work that raised it, and the way into it. A charge is
                     changed where it was made, never here: cancelling a line
                     from the bill used to leave the order saying it was done,
                     its evidence gone, and any drugs still off the shelf. --}}
                <td>
                  @if($line->order)
                    <button type="button" class="tb-rowbtn tb-bill-order"
                            wire:click="openOrder({{ $line->id }})"
                            title="Open the order that raised this charge">
                      <i class="fas {{ $line->order->type->icon() }}" aria-hidden="true"></i>
                      {{ $line->order->title }}
                      <i class="fas fa-arrow-right tb-bill-go" aria-hidden="true"></i>
                    </button>
                  @else
                    <span class="muted">—</span>
                  @endif
                </td>

                <td class="tb-text-right muted"><x-ui.money :amount="$line->unit_price" /></td>
                <td class="tb-text-right">{{ $line->tidyQuantity() }}</td>
                <td class="tb-text-right"><b><x-ui.money :amount="$line->line_total" /></b></td>
              </tr>
            @endforeach
          </tbody>
          <tfoot>
            <tr>
              <th colspan="4" class="tb-text-right">Charges</th>
              <th class="tb-text-right"><x-ui.money :amount="$this->totals['subtotal']" /></th>
            </tr>

            @if(bccomp($this->totals['discount'], '0', 2) > 0)
              <tr class="tb-bill-discount">
                <th colspan="4" class="tb-text-right">
                  Discount
                  @if($this->visit->discount_type === \App\Enums\DiscountType::Percent)
                    <em>{{ rtrim(rtrim((string) $this->visit->discount_value, '0'), '.') }}%</em>
                  @endif
                  @if($this->visit->discount_reason)<em>· {{ $this->visit->discount_reason }}</em>@endif
                  @if($this->visit->discountedBy)<em>· {{ $this->visit->discountedBy->name }}</em>@endif
                </th>
                <th class="tb-text-right">−<x-ui.money :amount="$this->totals['discount']" /></th>
              </tr>
            @endif

            {{-- Charged on what is actually paid: the discount comes off the
                 taxable part in the same proportion it comes off the bill. --}}
            @if(bccomp($this->totals['tax'], '0', 2) > 0)
              <tr>
                <th colspan="4" class="tb-text-right">
                  {{ $taxLabel }}
                  <em>on <x-ui.money :amount="$this->totals['taxable']" /></em>
                </th>
                <th class="tb-text-right"><x-ui.money :amount="$this->totals['tax']" /></th>
              </tr>
            @endif

            <tr>
              <th colspan="4" class="tb-text-right">
                @if($this->invoice)
                  {{-- Once there is an invoice, this figure is the WORK on the
                       visit, not what anybody owes. Saying "Total" twice, for
                       two different numbers, is how a screen makes a reader
                       distrust both. --}}
                  Work charged
                @else
                  {{ bccomp($this->totals['discount'], '0', 2) > 0 ? 'Due' : 'Total' }}
                @endif
              </th>
              <th class="tb-text-right tb-primary"><x-ui.money :amount="$this->totals['due']" /></th>
            </tr>
          </tfoot>
        </table>
      </div>

      @can('billing.manage')
        @unless($this->invoice)
          <p class="tb-bill-hint">
            <i class="fas fa-circle-info" aria-hidden="true"></i>
            A charge is changed on the order that raised it — that keeps the bill,
            the work and the pharmacy shelf saying the same thing.
          </p>
        @endunless
      @endcan
    @endif

    {{-- ── Once it is invoiced ───────────────────────────────────────── --}}
    @if($this->invoice)
      @php($invoice = $this->invoice)
      <div class="tb-inv">
        <div class="tb-inv-head">
          <span class="mono tb-inv-no">{{ $invoice->invoice_no }}</span>
          <x-ui.badge :tone="$invoice->status->badge()">{{ $invoice->status->label() }}</x-ui.badge>
          <span class="muted tb-small">raised {{ $invoice->issued_at?->format('d M Y · H:i') }}</span>
        </div>
        <dl class="tb-vs-facts">
          <div><dt>Invoiced</dt><dd><x-ui.money :amount="$invoice->total" /></dd></div>
          <div><dt>Paid</dt><dd><x-ui.money :amount="$invoice->amount_paid" /></dd></div>
          <div>
            <dt>Balance</dt>
            <dd @class(['tb-danger' => bccomp((string) $invoice->balance, '0.00', 2) > 0])>
              <x-ui.money :amount="$invoice->balance" />
            </dd>
          </div>
          @if(bccomp((string) $invoice->discount, '0.00', 2) > 0)
            <div><dt>Discount</dt><dd>−<x-ui.money :amount="$invoice->discount" /></dd></div>
          @endif
        </dl>
        <div class="tb-inv-acts">
          {{-- The invoice's own page is NOT offered here. Everything it holds
               — number, totals, balance, payments, the PDF — is already on this
               screen, so the link only ever threw somebody out of the visit
               they were working in to look at a second copy of what they were
               already reading. The PDF opens in its own tab, so this screen
               stays where it is (docs/documents.md). --}}
          <a href="{{ route('admin.invoices.pdf', $invoice) }}" target="_blank" rel="noopener"
             class="btn-tb btn-tb-sm">
            <i class="fas fa-file-pdf" aria-hidden="true"></i> Invoice PDF
          </a>
        </div>
        <p class="tb-bill-hint">
          <i class="fas fa-lock" aria-hidden="true"></i>
          The lines were snapshotted onto this invoice — the bill is fixed until it is voided.
        </p>

        {{-- The gap, said out loud. A bed charge is billed when the patient is
             discharged, which routinely happens after the counter has invoiced
             and been paid — and the two figures then sit one above the other,
             disagreeing, with nothing to say which one anybody owes. --}}
        @if(bccomp($this->unbilled, '0', 2) > 0)
          <p class="tb-bill-warn">
            <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
            <span>
              <strong><x-ui.money :amount="$this->unbilled" /></strong>
              of work was charged to this visit after {{ $invoice->invoice_no }} was raised,
              so it is on no invoice and nobody has been asked to pay it.
              @can('billing.manage')
                Void the invoice and raise it again to bill the whole visit.
              @endcan
            </span>
          </p>
        @endif
      </div>
    @endif
  </div>

  {{-- The next step, under the bill that opens it — with the one thing that
       opens it, so nobody has to work out what an invoice is for. --}}
  <x-ui.next-step :gate="$this->nextStep">
    <x-slot:action>
      @can('billing.manage')
        @unless($this->invoice)
          <button type="button" class="btn-tb btn-tb-sm btn-tb-primary" wire:click="openInvoice">
            <i class="fas fa-file-invoice-dollar" aria-hidden="true"></i> Generate invoice
          </button>
        @endunless
      @endcan
    </x-slot:action>
  </x-ui.next-step>

  {{-- ── Adding one ──────────────────────────────────────────────────── --}}
  <x-ui.modal size="md" show="showAdd" title="Add a charge">
    @if($showAdd)
      <form wire:submit="addLine" style="display:contents;">
        <div class="tb-modal-body">
          {{-- Same two kinds as an order's items, because that is what this
               raises. A product sold here leaves the shelf in the same
               transaction as the charge. --}}
          <div class="tb-seg" role="group" aria-label="What kind of thing is being charged">
            <button type="button" @class(['tb-seg-btn', 'is-on' => $kind === 'service'])
                    wire:click="$set('kind', 'service')"
                    aria-pressed="{{ $kind === 'service' ? 'true' : 'false' }}">
              <i class="fas fa-hand-holding-medical" aria-hidden="true"></i> Service
            </button>
            <button type="button" @class(['tb-seg-btn', 'is-on' => $kind === 'product'])
                    wire:click="$set('kind', 'product')"
                    aria-pressed="{{ $kind === 'product' ? 'true' : 'false' }}">
              <i class="fas fa-pills" aria-hidden="true"></i> Product
            </button>
            <span class="tb-seg-hint">
              {{ $kind === 'product' ? 'Comes off the pharmacy shelf' : 'From the price list' }}
            </span>
          </div>

          <x-ui.field :label="$kind === 'product' ? 'Product' : 'Service'"
                      :name="$kind === 'product' ? 'stock_item_id' : 'service_id'" required
                      :hint="$this->stockOnHand ? $this->stockOnHand.' on the shelf' : null">
            @if($kind === 'product')
              <livewire:ui.select-search resource="stock-items" name="stock_item_id"
                                         :selected="$stock_item_id" placeholder="Search the pharmacy…"
                                         :key="'ch-prod-'.$visitId.'-'.$formNonce" />
            @else
              <livewire:ui.select-search resource="services" name="service_id"
                                         :selected="$service_id" placeholder="Search the price list…"
                                         :key="'ch-svc-'.$visitId.'-'.$formNonce" />
            @endif
          </x-ui.field>

          <div class="tb-ordm-add-row">
            <x-ui.field label="Quantity" for="ch-qty" name="quantity" required>
              <input id="ch-qty" type="number" step="any" min="0.01" class="tb-input"
                     wire:model.live.debounce.300ms="quantity">
            </x-ui.field>

            {{-- Never typed. Price × quantity, in bcmath. --}}
            <div class="tb-ordm-total" aria-live="polite">
              <span class="k">Total</span>
              <b class="v"><x-ui.money :amount="$this->lineTotal" /></b>
              @if($this->pickedPrice)
                <span class="tb-ordm-calc">
                  <x-ui.money :amount="$this->pickedPrice" /> × {{ rtrim(rtrim($quantity, '0'), '.') ?: '0' }}
                </span>
              @endif
            </div>
          </div>

          <x-ui.field label="Note" for="ch-note" name="note">
            <input id="ch-note" type="text" maxlength="255" class="tb-input"
                   placeholder="Why this is on the bill — optional" wire:model="note">
          </x-ui.field>

          <p class="tb-bill-hint">
            <i class="fas fa-circle-info" aria-hidden="true"></i>
            This raises its own order, so the charge has a piece of work behind it
            like every other one on the bill.
          </p>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="$set('showAdd', false)">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="addLine">
            <span wire:loading.remove wire:target="addLine"><i class="fas fa-plus" aria-hidden="true"></i> Add to bill</span>
            <span wire:loading wire:target="addLine">Adding…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>

  {{-- ── The discount agreed at the counter ──────────────────────────── --}}
  <x-ui.modal size="sm" show="showDiscount" title="Discount">
    @if($showDiscount)
      {{-- Inline only. Blade pairs the FIRST php opener with the FIRST closer,
           before comments are stripped — so one block form below an inline one
           swallows every directive between them. --}}
      @php($entered = \App\Support\HospitalSettings::decimal($discountValue ?: '0', 2))
      @php($type = \App\Enums\DiscountType::tryFrom($discountType) ?? \App\Enums\DiscountType::Amount)
      @php($preview = $type->resolve($entered, $this->totals['subtotal']))

      <form wire:submit="saveDiscount" style="display:contents;">
        <div class="tb-modal-body">
          <p class="tb-small muted tb-mb-4">
            The charges come to <strong><x-ui.money :amount="$this->totals['subtotal']" /></strong>
            before {{ app(\App\Support\HospitalSettings::class)->taxLabel() }}.
          </p>

          {{-- A fixed amount is a number somebody negotiated; a percentage is
               a rule, and it follows the bill as lines come and go. --}}
          <div class="tb-seg" role="group" aria-label="How the discount was agreed">
            @foreach(\App\Enums\DiscountType::cases() as $case)
              <button type="button" @class(['tb-seg-btn', 'is-on' => $discountType === $case->value])
                      wire:key="dt-{{ $case->value }}"
                      wire:click="$set('discountType', '{{ $case->value }}')"
                      aria-pressed="{{ $discountType === $case->value ? 'true' : 'false' }}">
                {{ $case->label() }}
              </button>
            @endforeach
            <span class="tb-seg-hint">
              {{ $type === \App\Enums\DiscountType::Percent
                  ? 'Follows the bill as it changes'
                  : 'Stays the figure agreed' }}
            </span>
          </div>

          <x-ui.field :label="$type === \App\Enums\DiscountType::Percent ? 'Percentage' : 'Amount off'"
                      for="ch-disc" name="discountValue">
            <input id="ch-disc" type="number" step="any" min="0"
                   @if($type === \App\Enums\DiscountType::Percent) max="100" @endif
                   class="tb-input" placeholder="0"
                   wire:model.live.debounce.400ms="discountValue">
          </x-ui.field>

          <x-ui.field label="Why" for="ch-disc-why" name="discountReason">
            <input id="ch-disc-why" type="text" maxlength="255" class="tb-input"
                   placeholder="Staff rate · hardship · goodwill" wire:model="discountReason">
          </x-ui.field>

          @if(bccomp($preview, '0', 2) > 0)
            <div class="tb-disc-preview">
              <span>
                Comes off
                @if($type === \App\Enums\DiscountType::Percent)
                  <em>{{ rtrim(rtrim($entered, '0'), '.') }}% of {{ \App\Support\HospitalSettings::money($this->totals['subtotal']) }}</em>
                @endif
              </span>
              <b>−<x-ui.money :amount="$preview" /></b>
            </div>
          @endif

          <p class="tb-bill-hint">
            <i class="fas fa-user-shield" aria-hidden="true"></i>
            Your name is recorded against it, and
            {{ strtolower(app(\App\Support\HospitalSettings::class)->taxLabel()) }}
            is charged on what is actually paid.
          </p>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="$set('showDiscount', false)">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="saveDiscount">
            <span wire:loading.remove wire:target="saveDiscount"><i class="fas fa-check" aria-hidden="true"></i> Save discount</span>
            <span wire:loading wire:target="saveDiscount">Saving…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>

  {{-- ── Raising the invoice ─────────────────────────────────────────── --}}
  <x-ui.modal size="sm" show="showInvoice" title="Generate invoice">
    @if($showInvoice)
      <form wire:submit="generateInvoice" style="display:contents;">
        <div class="tb-modal-body">
          <dl class="tb-vs-facts">
            <div><dt>Charges</dt><dd><x-ui.money :amount="$this->totals['subtotal']" /></dd></div>
            @if(bccomp($this->totals['discount'], '0', 2) > 0)
              <div><dt>Discount</dt><dd>−<x-ui.money :amount="$this->totals['discount']" /></dd></div>
            @endif
            @if(bccomp($this->totals['tax'], '0', 2) > 0)
              <div>
                <dt>{{ app(\App\Support\HospitalSettings::class)->taxLabel() }}</dt>
                <dd><x-ui.money :amount="$this->totals['tax']" /></dd>
              </div>
            @endif
            <div><dt>To invoice</dt><dd><strong><x-ui.money :amount="$this->totals['due']" /></strong></dd></div>
          </dl>
          @error('discount')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
          <p class="tb-bill-hint">
            <i class="fas fa-lock" aria-hidden="true"></i>
            The lines are snapshotted onto the invoice. After this the bill is fixed —
            change a charge on its order first if anything is wrong.
          </p>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="$set('showInvoice', false)">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="generateInvoice">
            <span wire:loading.remove wire:target="generateInvoice"><i class="fas fa-file-invoice-dollar" aria-hidden="true"></i> Raise invoice</span>
            <span wire:loading wire:target="generateInvoice">Raising…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
