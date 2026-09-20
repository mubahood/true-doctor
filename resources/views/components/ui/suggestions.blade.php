@props([
    'options' => [],        // ['value' => 'Label'], or a plain list of values
    'set' => null,          // the Livewire property one click fills
    'current' => null,      // what the field holds now, so a match reads as chosen
    'sets' => [],           // ['option' => ['prop' => 'value', …]] for a pill answering several fields
    'lead' => null,         // a word before the row, e.g. "Common"
])
{{--
  One click instead of typing.

  The field still takes whatever you want to type — these are the answers this
  hospital gives most of the time, offered so nobody enters "08:00" for the four
  hundredth time. A pill lights up when the field already holds its value, so
  the row doubles as a reading of what is set.

      <x-ui.suggestions set="start_time" :current="$start_time"
                        :options="['08:00', '09:00', '14:00']" />

  A pill may answer more than one field at once — a clinic shift is a start AND
  an end, and asking for them separately is asking the same question twice:

      <x-ui.suggestions :current="$start_time.'–'.$end_time"
                        :options="['Morning clinic' => '08:00–12:00']"
                        :sets="['Morning clinic' => ['start_time' => '08:00', 'end_time' => '12:00']]" />

  Every pill is ONE round trip: the fields before the last are set deferred, and
  the last one commits them together.
--}}
@php
    // A plain list means the value IS the label; a map means key => label.
    // NOT `is_int($key)`: PHP silently casts "10" to int 10 when it becomes an
    // array key, so ['10' => '10 min'] looked like a list and the pill posted
    // "10 min" — a string — into an int property, which is a 500.
    $rows = [];
    $valuesAreLabels = array_is_list($options);

    foreach ($options as $key => $label) {
        $rows[$valuesAreLabels ? $label : $key] = $label;
    }
@endphp
@if($rows)
  <div class="tb-picker-pills" role="group" @if($lead) aria-label="{{ $lead }}" @endif>
    @if($lead)<span class="tb-pill-lead">{{ $lead }}</span>@endif

    @foreach($rows as $value => $label)
      @php
          $fills = $sets[$value] ?? ($set !== null ? [$set => $value] : []);
          $last = array_key_last($fills);
          $mine = $set !== null && array_key_exists($set, $fills) ? $fills[$set] : $value;
          $on = (string) ($current ?? '') !== '' && (string) $current === (string) $mine;

          $script = '';
          foreach ($fills as $prop => $val) {
              $defer = $prop === $last ? '' : ', false';
              $script .= sprintf('$wire.set(%s, %s%s); ',
                  \Illuminate\Support\Js::from($prop), \Illuminate\Support\Js::from($val), $defer);
          }
      @endphp
      <button type="button" @class(['tb-pill', 'is-on' => $on])
              wire:key="sg-{{ md5((string) $value.'|'.(string) $set) }}"
              x-data x-on:click="{{ $script }}">
        {{ $label }}
      </button>
    @endforeach
  </div>
@endif
