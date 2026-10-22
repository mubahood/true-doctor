{{-- Age or date of birth (docs/patients.md, "Age or date of birth").
     The age is the quick way at a desk; the date is a press away when it is known.
     Expects component properties dob_mode ('age' | 'date'), age_years, age_months, dob.
     $prefix makes the ids unique on a page. --}}
@php($prefix = $prefix ?? 'pt')
@if($this->dob_mode === 'date')
  <x-ui.field label="Date of birth" :for="$prefix.'-dob'" name="dob">
    <input id="{{ $prefix }}-dob" type="date" wire:model="dob" class="tb-input" max="{{ now()->toDateString() }}">
    <button type="button" class="tb-linkbtn tb-age-switch" wire:click="$set('dob_mode', 'age')">Enter the age instead</button>
  </x-ui.field>
@else
  <x-ui.field label="Age" :for="$prefix.'-age'" name="age_years">
    <div class="tb-age" x-data>
      <input id="{{ $prefix }}-age" type="number" min="0" max="{{ \App\Support\BirthDate::MAX_YEARS }}" step="1" inputmode="numeric"
             wire:model="age_years" class="tb-input" aria-describedby="{{ $prefix }}-age-unit">
      <span id="{{ $prefix }}-age-unit" class="tb-age-unit">years</span>
      <template x-if="$wire.age_years === null || $wire.age_years === '' || Number($wire.age_years) < {{ \App\Support\BirthDate::MONTHS_UNDER }}">
        <span class="tb-age">
          <label class="sr-only" for="{{ $prefix }}-age-months">Months</label>
          <input id="{{ $prefix }}-age-months" type="number" min="0" max="23" step="1" inputmode="numeric"
                 wire:model="age_months" class="tb-input" placeholder="0">
          <span class="tb-age-unit">months</span>
        </span>
      </template>
    </div>
    @error('age_months')<div class="tb-field-error">{{ $message }}</div>@enderror
    <button type="button" class="tb-linkbtn tb-age-switch" wire:click="$set('dob_mode', 'date')">Enter the date of birth instead</button>
  </x-ui.field>
@endif
