<div class="tb-card">
  <div class="tb-card-header">
    <span class="tb-card-title"><i class="fas fa-notes-medical" aria-hidden="true"></i> Coded diagnoses</span>
    <span class="muted tb-xs">ICD-10, for the Ministry reports</span>
  </div>
  <div class="tb-card-body">
    @if($this->current->isNotEmpty())
      <ul class="dx-list">
        @foreach($this->current as $d)
          <li wire:key="dx-{{ $d->id }}" @class(['dx-item', 'is-primary' => $d->is_primary])>
            <span class="mono dx-code">{{ $d->code }}</span>
            <span class="dx-title">{{ $d->title }}</span>
            @if($d->is_primary)<x-ui.badge tone="info">Primary</x-ui.badge>@endif
            @if($d->certainty === 'suspected')<x-ui.badge tone="warn">Suspected</x-ui.badge>@endif
            @can('diagnose', $this->visit)
              <span class="dx-acts">
                @unless($d->is_primary)
                  <button type="button" class="btn-tb btn-tb-sm btn-tb-ghost" wire:click="makePrimary({{ $d->id }})" wire:loading.attr="disabled" wire:target="makePrimary">Make primary</button>
                @endunless
                <button type="button" class="btn-tb btn-tb-sm btn-tb-ghost" wire:click="toggleCertainty({{ $d->id }})" wire:loading.attr="disabled" wire:target="toggleCertainty">{{ $d->certainty === 'confirmed' ? 'Mark suspected' : 'Mark confirmed' }}</button>
                <button type="button" class="btn-tb btn-tb-sm btn-tb-ghost" wire:click="remove({{ $d->id }})" wire:loading.attr="disabled" wire:target="remove" aria-label="Remove {{ $d->title }}"><i class="fas fa-xmark" aria-hidden="true"></i></button>
              </span>
            @endcan
          </li>
        @endforeach
      </ul>
    @elseif($this->visit->diagnosis)
      <p class="muted tb-small">Not coded yet. The written diagnosis: "{{ \Illuminate\Support\Str::limit($this->visit->diagnosis, 120) }}"</p>
    @else
      <x-ui.empty compact icon="fa-notes-medical" message="No diagnosis yet." />
    @endif

    @can('diagnose', $this->visit)
      <div class="dx-pick">
        <input type="search" class="tb-input" wire:model.live.debounce.250ms="q" placeholder="Type the diagnosis in plain words or a code…" aria-label="Find a diagnosis">
        <label class="tb-check-group"><input type="checkbox" wire:model="suspected"> Suspected</label>
      </div>
      @php($options = $q !== '' ? $this->matches : $this->suggested)
      @if($options->isNotEmpty())
        <div class="dx-options" wire:loading.class="tb-loading" wire:target="q">
          @if($q === '')<span class="muted tb-xs">From the written diagnosis:</span>@endif
          @foreach($options as $o)
            <button type="button" class="dx-option" wire:key="opt-{{ $o->code }}" wire:click="add('{{ $o->code }}')" wire:loading.attr="disabled" wire:target="add">
              <span class="mono">{{ $o->code }}</span> {{ $o->title }}
            </button>
          @endforeach
        </div>
      @elseif($q !== '')
        <p class="muted tb-xs">Nothing matches "{{ $q }}". Try other words, or the code.</p>
      @endif
    @endcan
  </div>
</div>
