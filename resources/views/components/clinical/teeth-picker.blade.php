@props(['model', 'selected' => [], 'id' => 'teeth'])
{{--
  One or more teeth (FDI) for a procedure order, as the dentist faces the
  patient. "Child's teeth" switches to the milk teeth in the browser; ticks on
  either set are kept. `model` is the Livewire array property.
--}}
@php($chosen = array_map('intval', (array) $selected))
<div class="teeth-pick" x-data="{ child: {{ collect($chosen)->contains(fn ($t) => $t > 50) ? 'true' : 'false' }} }">
  <label class="tb-check-group tb-xs"><input type="checkbox" x-model="child"> Child's teeth</label>
  @foreach([false, true] as $primary)
    <div class="teeth-pick-set" x-show="{{ $primary ? 'child' : '! child' }}" @if($primary) x-cloak @endif>
      @foreach(\App\Services\DentalService::rows($primary) as [$right, $left])
        <div class="teeth-pick-row">
          @foreach([...$right, ...$left] as $t)
            <label class="tb-chip-check" wire:key="{{ $id }}-{{ $t }}">
              <input type="checkbox" value="{{ $t }}" wire:model="{{ $model }}"> {{ $t }}
            </label>
          @endforeach
        </div>
      @endforeach
    </div>
  @endforeach
</div>
