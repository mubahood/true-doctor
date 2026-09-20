<div class="tb-card">
  <div class="tb-card-header">
    <span class="tb-card-title"><i class="fas fa-hand-holding-dollar" aria-hidden="true"></i> Payments</span>
    @can('billing.manage')
      @if($this->invoice && bccomp((string) $this->invoice->balance, '0.00', 2) > 0)
        <button type="button" class="btn-tb btn-tb-sm btn-tb-primary" wire:click="openTake">
          <i class="fas fa-plus" aria-hidden="true"></i> Take payment
        </button>
      @endif
    @endcan
  </div>

  <div class="tb-card-body">
    @if($this->invoice === null)
      <x-ui.empty compact icon="fa-hand-holding-dollar"
                  message="Nothing to pay yet — the bill has not been invoiced." />
    @else
      @php($settled = bccomp((string) $this->invoice->balance, '0.00', 2) <= 0)

      <div class="tb-pay-state">
        <div><span class="k">Invoiced</span><x-ui.money :amount="$this->invoice->total" class="v" /></div>
        <div><span class="k">Paid</span><x-ui.money :amount="$this->invoice->amount_paid" class="v" /></div>
        <div>
          <span class="k">Balance</span>
          @if($settled)
            <span class="v ok"><i class="fas fa-circle-check" aria-hidden="true"></i> Settled</span>
          @else
            <x-ui.money :amount="$this->invoice->balance" class="v due" />
          @endif
        </div>
      </div>

      {{-- How far along it is, before the detail of who paid what. --}}
      @unless($settled)
        <x-ui.progress :value="(float) $this->invoice->amount_paid" :max="(float) $this->invoice->total"
                       label="Paid so far" class="tb-pay-bar" />
      @endunless

      @if($this->payments->isNotEmpty())
        <div class="tb-table-wrap">
          <table class="tb-table">
            <caption class="sr-only">Payments against this visit</caption>
            <thead>
              <tr>
                <th>When</th>
                <th>Method</th>
                <th class="tb-text-right">Amount</th>
                <th class="tb-text-right">Balance after</th>
                <th>Taken by</th>
                <th><span class="sr-only">Receipt</span></th>
              </tr>
            </thead>
            <tbody>
              @foreach($this->payments as $payment)
                <tr wire:key="pay-{{ $payment->id }}">
                  <td class="tb-nowrap">{{ $payment->created_at?->format('d M · H:i') }}</td>
                  <td>
                    {{ $payment->method->label() }}
                    {{-- The reference is the way back to somebody else's
                         record when these figures are questioned. --}}
                    @if($payment->reference)
                      <span class="tb-pay-ref">{{ $payment->reference }}</span>
                    @endif
                  </td>
                  <td class="tb-text-right"><b><x-ui.money :amount="$payment->amount" /></b></td>
                  <td class="tb-text-right muted"><x-ui.money :amount="$payment->balance_after" /></td>
                  <td class="muted">{{ $payment->receivedBy?->name ?? '—' }}</td>
                  <td class="tb-text-right">
                    {{-- Opens in its own tab, so the visit stays where it is
                         while the receipt is read (docs/documents.md). --}}
                    <a href="{{ route('admin.payments.receipt', $payment) }}" target="_blank" rel="noopener"
                       class="btn-tb btn-tb-sm btn-tb-ghost" title="Receipt for this payment">
                      <i class="fas fa-receipt" aria-hidden="true"></i> Receipt
                    </a>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @else
        <x-ui.empty compact icon="fa-hand-holding-dollar" message="No payment taken yet." />
      @endif
    @endif
  </div>

  {{-- The next step, under the settling that opens it. --}}
  <x-ui.next-step :gate="$this->nextStep" />

  {{-- ── Taking one ─────────────────────────────────────────────── --}}
  <x-ui.modal size="md" show="showTake" title="Take payment">
    @if($showTake && $this->invoice)
      <form wire:submit="take" style="display:contents;">
        <div class="tb-modal-body">
          <p class="tb-small muted tb-mb-4">
            {{ $this->invoice->invoice_no }} — balance
            <strong><x-ui.money :amount="$this->invoice->balance" /></strong>
          </p>

          {{-- The ways money is taken at a counter. A gateway is not one of
               them: it settles itself and is recorded once verified. --}}
          <fieldset class="tb-kinds">
            <legend class="tb-label">How is it being paid?</legend>
            <div class="tb-kind-grid">
              @foreach($this->methods as $case)
                <label @class(['tb-kind', 'is-on' => $method === $case->value]) wire:key="pm-{{ $case->value }}">
                  <input type="radio" class="sr-only" name="pay-method" value="{{ $case->value }}"
                         wire:model.live="method">
                  <i class="fas {{ match($case) {
                      \App\Enums\PaymentMethod::Cash => 'fa-money-bill-wave',
                      \App\Enums\PaymentMethod::Card => 'fa-credit-card',
                      \App\Enums\PaymentMethod::MobileMoney => 'fa-mobile-screen',
                      \App\Enums\PaymentMethod::Bank => 'fa-building-columns',
                      default => 'fa-shield-heart',
                  } }}" aria-hidden="true"></i>
                  <span>{{ $case->label() }}</span>
                </label>
              @endforeach
            </div>
            @error('method')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
          </fieldset>

          {{-- What this method needs, and nothing it does not. --}}
          @if($method === \App\Enums\PaymentMethod::Card->value)
            <x-ui.field label="Card" for="pay-card" name="patient_card_id" required
                        :hint="$this->cardBalance !== null ? \App\Support\HospitalSettings::money($this->cardBalance).' on the card' : null">
              @if($this->cards->isEmpty())
                <p class="tb-small muted">This patient has no prepaid card. Issue one from their record first.</p>
              @else
                <select id="pay-card" class="tb-select" wire:model.live="patient_card_id">
                  <option value="">— choose a card —</option>
                  @foreach($this->cards as $card)
                    <option value="{{ $card->id }}" @disabled(! $card->status->canTransact() || $card->isExpired())>
                      {{ $card->masked() }} · {{ \App\Support\HospitalSettings::money($card->balance) }}
                      @if(! $card->status->canTransact()) · {{ $card->status->label() }}
                      @elseif($card->isExpired()) · expired @endif
                    </option>
                  @endforeach
                </select>
              @endif
            </x-ui.field>
          @endif

          @if($this->needsReference)
            <x-ui.field label="Reference" for="pay-ref" name="reference" required
                        :hint="match($method) {
                            \App\Enums\PaymentMethod::MobileMoney->value => 'The transaction ID from the phone',
                            \App\Enums\PaymentMethod::Bank->value => 'The slip or transfer number',
                            default => 'The claim or authorisation number',
                        }">
              <input id="pay-ref" type="text" maxlength="120" class="tb-input" wire:model="reference">
            </x-ui.field>
          @endif

          <x-ui.field label="Amount" for="pay-amount" name="amount" required>
            <div class="tb-pay-amount">
              <input id="pay-amount" type="number" step="any" min="0.01" class="tb-input"
                     wire:model="amount" required>
              @if(bccomp((string) $this->invoice->balance, (string) ($amount ?: '0'), 2) !== 0)
                <button type="button" class="btn-tb btn-tb-sm btn-tb-ghost" wire:click="payInFull">
                  Full balance
                </button>
              @endif
            </div>
          </x-ui.field>

          <p class="tb-bill-hint">
            <i class="fas fa-circle-info" aria-hidden="true"></i>
            A part payment is fine — the visit closes itself once the balance reaches zero.
          </p>
        </div>

        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="$set('showTake', false)">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="take">
            <span wire:loading.remove wire:target="take"><i class="fas fa-check" aria-hidden="true"></i> Record payment</span>
            <span wire:loading wire:target="take">Recording…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
