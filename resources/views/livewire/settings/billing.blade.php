<div>
  <x-ui.page-header title="Billing settings" :crumbs="['Dashboard' => route('admin.dashboard'), 'Billing settings' => null]">
    <x-slot:explain>
      How money is written down everywhere in the system — on screen, on invoices and
      on receipts. Changing it re-formats what is already recorded; it does not convert it.
    </x-slot:explain>
  </x-ui.page-header>

  <form wire:submit="save">
    <div class="tb-letterhead">
      <div class="tb-lh-form">
        {{-- Currency ─────────────────────────────────────────────── --}}
        <div class="tb-set-sec">Currency</div>

        {{-- A shilling has no cents and a dollar has two. Offering the code,
             the symbol and the decimal places as three unrelated fields is how
             a UGX hospital ends up printing "USh 30,000.00". --}}
        <div class="tb-picker-pills tb-mb-3">
          <span class="tb-pill-lead">Common</span>
          @foreach($this->currencies() as $code => $meta)
            <button type="button" @class(['tb-pill', 'is-on' => strtoupper($currency_code) === $code])
                    wire:click="useCurrency('{{ $code }}')">{{ $code }} · {{ $meta['symbol'] }}</button>
          @endforeach
        </div>

        <div class="tb-form-grid">
          <x-ui.field label="Currency code" for="bill-code" name="currency_code" required>
            <input id="bill-code" type="text" wire:model.live.debounce.300ms="currency_code" class="tb-input" placeholder="UGX" required>
          </x-ui.field>
          <x-ui.field label="Symbol" for="bill-symbol" name="currency_symbol">
            <input id="bill-symbol" type="text" wire:model.live.debounce.300ms="currency_symbol" class="tb-input" placeholder="USh">
          </x-ui.field>
          <x-ui.field label="Symbol position" for="bill-position" name="currency_position" required>
            <select id="bill-position" wire:model.live="currency_position" class="tb-select">
              <option value="before">Before the amount</option>
              <option value="after">After the amount</option>
            </select>
          </x-ui.field>
          <x-ui.field label="Decimal places" for="bill-decimals" name="decimals" required>
            <select id="bill-decimals" wire:model.live="decimals" class="tb-select">
              <option value="0">0 — no cents</option>
              <option value="2">2</option>
              <option value="3">3</option>
            </select>
          </x-ui.field>
          <x-ui.field label="Thousands separator" for="bill-thousands" name="thousands_separator">
            <input id="bill-thousands" type="text" wire:model.live="thousands_separator" class="tb-input" maxlength="1">
          </x-ui.field>
          <x-ui.field label="Decimal separator" for="bill-decimal" name="decimal_separator" required>
            <input id="bill-decimal" type="text" wire:model.live="decimal_separator" class="tb-input" maxlength="1" required>
          </x-ui.field>
        </div>

        {{-- Tax ───────────────────────────────────────────────────── --}}
        <div class="tb-set-sec">Tax</div>

        <label class="tb-check-group tb-mb-2">
          <input type="checkbox" wire:model.live="tax_enabled"> Charge tax on invoices
        </label>

        {{-- The label and the rate mean nothing with tax switched off, and a
             form that asks for them anyway is asking a question it will ignore. --}}
        @if($tax_enabled)
          <div class="tb-form-grid">
            <x-ui.field label="What it is called" for="bill-taxlabel" name="tax_label">
              <input id="bill-taxlabel" type="text" wire:model.live.debounce.300ms="tax_label" class="tb-input" placeholder="VAT">
              <x-ui.suggestions set="tax_label" :current="$tax_label" :options="['VAT', 'GST', 'Sales tax']" />
            </x-ui.field>
            <x-ui.field label="Rate (%)" for="bill-taxrate" name="tax_rate">
              <input id="bill-taxrate" type="number" step="0.01" min="0" max="100"
                     wire:model.live.debounce.300ms="tax_rate" class="tb-input">
              <x-ui.suggestions set="tax_rate" :current="$tax_rate" :options="['16', '18', '20']" />
            </x-ui.field>
          </div>
        @endif

        {{-- Invoices ──────────────────────────────────────────────── --}}
        <div class="tb-set-sec">Invoices</div>

        <div class="tb-form-grid">
          <x-ui.field label="Number prefix" for="bill-prefix" name="invoice_prefix" required>
            <input id="bill-prefix" type="text" wire:model.live.debounce.300ms="invoice_prefix" class="tb-input" required maxlength="10">
          </x-ui.field>
          <x-ui.field label="Default visit fee" for="bill-fee" name="consultation_fee"
                      hint="Charged when a visit is opened with nothing else on it.">
            <input id="bill-fee" type="number" step="0.01" min="0" wire:model.live.debounce.400ms="consultation_fee" class="tb-input">
          </x-ui.field>
          <x-ui.field label="Footer line" for="bill-footer" name="invoice_footer" class="full"
                      hint="The last line of every document. Same setting as the footer under Hospital letterhead.">
            <input id="bill-footer" type="text" wire:model.live.debounce.300ms="invoice_footer" class="tb-input" maxlength="500">
          </x-ui.field>
        </div>
      </div>

      {{-- Everything that shapes an invoice, shown as the invoice it shapes.
           A rate is not an amount and a prefix is not a number; both were
           being chosen blind. --}}
      <aside class="tb-lh-preview" aria-label="What this looks like">
        <div class="tb-lh-preview-label">What this looks like</div>

        <div class="tb-set-preview" aria-live="polite">
          <div class="tb-set-row">
            <span>An amount</span>
            <b class="mono">{{ $this->preview }}</b>
          </div>
          <div class="tb-set-row">
            <span>Next invoice</span>
            <b class="mono">{{ $this->invoicePreview() }}</b>
          </div>

          @php($tax = $this->taxPreview())
          <div class="tb-set-rule"></div>
          <div class="tb-set-row"><span>A bill of</span><b class="mono">{{ $tax['net'] }}</b></div>
          @if($tax_enabled)
            <div class="tb-set-row">
              <span>{{ $tax_label ?: 'Tax' }} at {{ rtrim(rtrim(number_format((float) ($tax_rate ?? 0), 2), '0'), '.') ?: '0' }}%</span>
              <b class="mono">{{ $tax['tax'] }}</b>
            </div>
          @else
            <div class="tb-set-row"><span class="muted">No tax charged</span><b class="mono muted">—</b></div>
          @endif
          <div class="tb-set-row is-total"><span>The patient pays</span><b class="mono">{{ $tax['gross'] }}</b></div>

          <div class="tb-set-foot">{{ $invoice_footer ?: 'Your footer line appears here.' }}</div>
        </div>

        {{-- Changing the code RE-LABELS what is already recorded. It does not
             convert it: four hundred invoices in shillings become four hundred
             invoices in dollars, at the same numbers. --}}
        @if($this->currencyIsChanging())
          @php($recorded = $this->recorded)
          <div class="tb-warnbar tb-mt-3">
            <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
            <span>
              <b>{{ $recorded['invoices'] }}</b> {{ \Illuminate\Support\Str::plural('invoice', $recorded['invoices']) }}
              and <b>{{ $recorded['payments'] }}</b> {{ \Illuminate\Support\Str::plural('payment', $recorded['payments']) }}
              are already recorded in <b>{{ $recorded['currency'] }}</b>. Saving re-labels them as
              <b>{{ strtoupper($currency_code) ?: '—' }}</b> at the same numbers — nothing is converted.
            </span>
          </div>
        @endif

        <div class="tb-lh-save">
          <button type="submit" class="btn-tb btn-tb-primary"
                  wire:loading.attr="disabled" wire:target="save"
                  @if($this->currencyIsChanging())
                    wire:confirm="Re-label every recorded amount as {{ strtoupper($currency_code) }}? The numbers do not change."
                  @endif>
            <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> Save billing settings</span>
            <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
          </button>
        </div>
      </aside>
    </div>
  </form>
</div>
