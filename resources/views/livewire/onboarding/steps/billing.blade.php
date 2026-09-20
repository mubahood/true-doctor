<form wire:submit="save">
  <div class="tb-form-grid">
    <x-ui.field label="Currency" for="wz-cur" name="currency_code" required
                hint="Every price and invoice is stored in this currency.">
      <select id="wz-cur" wire:model.live="currency_code" class="tb-select" required>
        @foreach($this->currencies as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
      </select>
    </x-ui.field>

    <x-ui.field label="Symbol" for="wz-sym" name="currency_symbol">
      <input id="wz-sym" type="text" wire:model.live="currency_symbol" class="tb-input" maxlength="8">
    </x-ui.field>

    <x-ui.field label="Symbol position" for="wz-pos" name="currency_position" required>
      <select id="wz-pos" wire:model.live="currency_position" class="tb-select" required>
        <option value="before">Before the amount</option>
        <option value="after">After the amount</option>
      </select>
    </x-ui.field>

    <x-ui.field label="Decimal places" for="wz-dec" name="decimals" required>
      <select id="wz-dec" wire:model.live="decimals" class="tb-select" required>
        <option value="0">0 — whole amounts</option>
        <option value="2">2</option>
        <option value="3">3</option>
      </select>
    </x-ui.field>

    <x-ui.field label="Invoice prefix" for="wz-pre" name="invoice_prefix" required
                hint="Invoices are numbered {{ $invoice_prefix ?: 'INV' }}-{{ date('Y') }}-00001.">
      <input id="wz-pre" type="text" wire:model.live="invoice_prefix" class="tb-input" maxlength="10" required>
    </x-ui.field>

    <div class="tb-form-group">
      <span class="tb-label">Preview</span>
      <div class="tb-panel tb-flex" aria-live="polite">
        <span class="tb-fw-600">{{ $this->preview }}</span>
      </div>
    </div>
  </div>

  <div class="wz-actions">
    <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
      <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> Save</span>
      <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
    </button>
  </div>
</form>
