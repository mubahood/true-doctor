@props(['model', 'except' => [], 'id' => 'tf'])
{{--
  The findings catalogue (App\Enums\DentalFinding) as radio buttons, for the
  tooth dialog. `model` is the Livewire property the choice goes into; the
  mobility grade follows when Mobility is chosen (wire:model.live).
--}}
<fieldset class="tooth-opts">
  <legend class="tb-label">Status</legend>
  @foreach(\App\Enums\DentalFinding::cases() as $f)
    @continue(in_array($f->value, $except, true))
    <label class="tb-check-group" wire:key="{{ $id }}-{{ $f->value }}">
      <input type="radio" name="{{ $id }}-finding" value="{{ $f->value }}" wire:model.live="{{ $model }}"> {{ $f->label() }}
    </label>
  @endforeach
</fieldset>
