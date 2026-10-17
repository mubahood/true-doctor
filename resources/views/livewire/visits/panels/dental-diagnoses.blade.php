<div class="cs-section">
  @if($this->current !== [])
    <ul class="dx-list">
      @foreach($this->current as $d)
        <li wire:key="cdx-{{ $d['id'] }}" @class(['dx-item', 'is-primary' => $d['primary']])>
          <span class="mono cs-tooth">{{ $d['tooth'] !== null ? $d['tooth'].($d['surfaces'] ? ' '.$d['surfaces'] : '') : 'Mouth' }}</span>
          <span class="dx-title">{{ $d['label'] }}</span>
          <span class="mono dx-code">{{ $d['code'] }}</span>
          @if($d['suspected'])<x-ui.badge tone="warn">Suspected</x-ui.badge>@endif
          @if($canWrite)
            <span class="dx-acts">
              <x-ui.icon-button label="Remove {{ $d['label'] }}" icon="fa-xmark" wire:click="remove({{ $d['id'] }})" wire:confirm="Take this diagnosis off the visit?" />
            </span>
          @endif
        </li>
      @endforeach
    </ul>
  @else
    <p class="muted tb-xs cs-empty">No diagnosis yet.</p>
  @endif

  @if($canWrite)
    <div class="cs-dx-pick">
      <div class="tb-flex">
        <label class="tb-label" for="cdx-tooth">Tooth</label>
        <input id="cdx-tooth" type="number" class="tb-input tb-input-slim cs-tooth-in" wire:model.live="tooth" min="11" max="85" placeholder="35">
        <span class="cs-chips" role="group" aria-label="Surfaces">
          @foreach(['M' => 'Mesial', 'O' => 'Occlusal', 'D' => 'Distal', 'B' => 'Buccal', 'L' => 'Lingual', 'I' => 'Incisal'] as $code => $name)
            <label class="tb-chip-check" title="{{ $name }}"><input type="checkbox" value="{{ $code }}" wire:model="surfaces"> {{ $code }}</label>
          @endforeach
        </span>
        <label class="tb-check-group"><input type="checkbox" wire:model="suspected"> Suspected</label>
      </div>
      @error('tooth')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
      <p class="muted tb-xs">Pick a tooth on the chart above, or type it, then tap the diagnosis. It is coded and marked on the chart.</p>

      <div class="cs-chips" role="group" aria-label="Diagnoses of a tooth">
        @foreach($list as $key => $item)
          @continue($item['tooth'] === false)
          <button type="button" class="tb-pill" wire:click="add('{{ $key }}')" wire:loading.attr="disabled" wire:target="add" title="{{ $item['code'] }}">{{ $item['label'] }}</button>
        @endforeach
      </div>
      <div class="cs-chips" role="group" aria-label="Diagnoses of the mouth">
        <span class="muted tb-xs">Mouth:</span>
        @foreach($list as $key => $item)
          @continue($item['tooth'] !== false)
          <button type="button" class="tb-pill" wire:click="add('{{ $key }}')" wire:loading.attr="disabled" wire:target="add" title="{{ $item['code'] }}">{{ $item['label'] }}</button>
        @endforeach
      </div>

      <div class="dx-pick">
        <input type="search" class="tb-input" wire:model.live.debounce.250ms="q" placeholder="Something else: type it in plain words or a code" aria-label="Find another diagnosis">
      </div>
      @error('q')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
      @if($this->matches->isNotEmpty())
        <div class="dx-options">
          @foreach($this->matches as $o)
            <button type="button" class="dx-option" wire:key="cdxo-{{ $o->code }}" wire:click="addCode('{{ $o->code }}')" wire:loading.attr="disabled" wire:target="addCode">
              <span class="mono">{{ $o->code }}</span> {{ $o->title }}
            </button>
          @endforeach
        </div>
      @elseif(trim($q) !== '')
        <p class="muted tb-xs">Nothing matches "{{ $q }}".</p>
      @endif
    </div>
  @endif
</div>
