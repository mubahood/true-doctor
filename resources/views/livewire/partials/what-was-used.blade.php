{{-- What this piece of work USED, and what it comes to.

     A consultation, a blood test and an X-ray all consume things — a service
     off the price list, a product off the shelf — so the question is asked the
     same way wherever it comes up (App\Livewire\Concerns\ChargesWorkDone).
     `lead` names it for the screen it is on. --}}
<div class="tb-out-sec">
  <span>{{ $lead ?? 'What this used' }}</span>
  <span class="tb-out-hint">{{ $hint ?? 'Charged to the visit when this is submitted.' }}</span>
</div>

<div class="tb-out-pickers">
  <livewire:ui.select-search resource="services" name="provided_service_id"
    placeholder="Add a service…" :key="'used-svc-'.$formNonce" />
  <livewire:ui.select-search resource="stock-items" name="provided_product_id"
    placeholder="Add a product off the shelf…" :key="'used-prod-'.$formNonce" />
</div>

@if($provided)
  <table class="tb-table tb-out-lines">
    <caption class="sr-only">What is being charged for this work</caption>
    <thead><tr>
      <th>Item</th>
      <th class="tb-text-right">Price</th>
      <th class="tb-text-right">Qty</th>
      <th class="tb-text-right">Amount</th>
      <th><span class="sr-only">Remove</span></th>
    </tr></thead>
    <tbody>
      @foreach($provided as $i => $line)
        <tr wire:key="used-{{ $line['kind'] }}-{{ $line['id'] }}">
          <td class="tb-fw-500">
            {{ $line['name'] }}
            <span class="tb-out-kind">{{ $line['kind'] === 'product' ? 'Product' : 'Service' }}</span>
            @if($line['kind'] === 'product' && $line['stock'] !== null)
              <span class="tb-out-stock">{{ $line['stock'] }} left on the shelf</span>
            @endif
          </td>
          <td class="tb-text-right muted mono">{{ \App\Support\HospitalSettings::money($line['unit_price']) }}</td>
          <td class="tb-text-right">
            <input type="number" min="1" step="1" class="tb-input tb-qty"
                   wire:model.live.debounce.400ms="provided.{{ $i }}.quantity"
                   aria-label="How many {{ $line['name'] }}">
          </td>
          <td class="tb-text-right mono">{{ \App\Support\HospitalSettings::money(bcmul((string) $line['unit_price'], (string) $line['quantity'], 4)) }}</td>
          <td class="tb-text-right">
            <x-ui.icon-button label="Remove {{ $line['name'] }}" icon="fa-xmark" wire:click="removeProvided({{ $i }})" />
          </td>
        </tr>
      @endforeach
    </tbody>
    <tfoot><tr>
      <th colspan="3" class="tb-text-right">Total</th>
      <th class="tb-text-right mono">{{ \App\Support\HospitalSettings::money($this->providedTotal()) }}</th>
      <th></th>
    </tr></tfoot>
  </table>
@else
  <p class="tb-out-none">Nothing added. This work is charged at what was already ordered.</p>
@endif

@error('provided.*.quantity')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
