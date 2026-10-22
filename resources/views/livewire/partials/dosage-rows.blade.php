{{-- The dosage rows (App\Livewire\Concerns\WritesDosage; docs/pharmacy.md, "Dosage"):
     the visit page's Dosage panel and a dental clinic's new visit form.
     $keep: how many rows must stay (1 in the panel, 0 in the optional form). --}}
@php($keep = $keep ?? 1)
@error('dosage')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
@foreach($dosage as $index => $row)
  <div class="tb-dose-row" wire:key="dose-row-{{ $row['uid'] }}">
    <x-ui.field label="Medicine" :name="'dosage.'.$index.'.stock_item_id'" required>
      <livewire:ui.select-search resource="medicines"
                                 :name="'dose-'.$row['uid']"
                                 :selected="$row['stock_item_id']"
                                 placeholder="Search medicines"
                                 :key="'dose-pick-'.$row['uid']" />
    </x-ui.field>
    <x-ui.field label="Dose" :for="'dose-dose-'.$row['uid']" :name="'dosage.'.$index.'.dose'" required>
      <input id="dose-dose-{{ $row['uid'] }}" type="text" class="tb-input" maxlength="60" placeholder="1 tablet"
             wire:model="dosage.{{ $index }}.dose">
    </x-ui.field>
    <x-ui.field label="How often" :for="'dose-freq-'.$row['uid']" :name="'dosage.'.$index.'.frequency'" required>
      <select id="dose-freq-{{ $row['uid'] }}" class="tb-select" wire:model.live="dosage.{{ $index }}.frequency">
        <option value="">Choose</option>
        @foreach($this->doseFrequencies() as $frequency)
          <option value="{{ $frequency }}">{{ $frequency }}</option>
        @endforeach
        <option value="other">Other</option>
      </select>
      @if(($row['frequency'] ?? '') === 'other')
        <input type="text" class="tb-input tb-mt-2" maxlength="60" placeholder="Every 6 hours"
               aria-label="How often, in words" wire:model="dosage.{{ $index }}.frequency_other">
      @endif
    </x-ui.field>
    <x-ui.field label="Days" :for="'dose-days-'.$row['uid']" :name="'dosage.'.$index.'.days'" required>
      <input id="dose-days-{{ $row['uid'] }}" type="number" min="1" max="90" class="tb-input"
             wire:model="dosage.{{ $index }}.days">
    </x-ui.field>
    <x-ui.field label="Note" :for="'dose-note-'.$row['uid']" :name="'dosage.'.$index.'.note'">
      <input id="dose-note-{{ $row['uid'] }}" type="text" class="tb-input" maxlength="255" placeholder="After meals"
             wire:model="dosage.{{ $index }}.note">
    </x-ui.field>
    @if(count($dosage) > $keep)
      <x-ui.icon-button label="Remove medicine {{ $index + 1 }}" icon="fa-trash" variant="danger"
                        class="tb-dose-remove" wire:click="removeDoseRow({{ $index }})" />
    @endif
  </div>
@endforeach
<button type="button" class="btn-tb btn-tb-sm" wire:click="addDoseRow">
  <i class="fas fa-plus" aria-hidden="true"></i> {{ count($dosage) === 0 ? 'Add a medicine' : 'Add another medicine' }}
</button>
