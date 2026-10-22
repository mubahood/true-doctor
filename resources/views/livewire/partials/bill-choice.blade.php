{{-- Billed or not billed, for one product line (docs/orders.md).

     Not billed still takes the stock off the shelf; only the charge is left
     off. `name` groups the two radios, `billed` is the current choice, and
     `on` / `off` are the Livewire actions for each. --}}
<span class="tb-billq" role="radiogroup" aria-label="{{ $label ?? 'Billed or not billed' }}">
  <label><input type="radio" name="{{ $name }}" value="1" @checked($billed) wire:click="{{ $on }}"> Billed</label>
  <label><input type="radio" name="{{ $name }}" value="0" @checked(! $billed) wire:click="{{ $off }}"> Not billed</label>
</span>
