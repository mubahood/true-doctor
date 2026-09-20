{{--
  Async typeahead picker (App\Livewire\Ui\SelectSearch).

  Focus opens a capped list of what there is, with the likely picks at the top;
  typing narrows it. Nobody has to guess a name to find out what is on offer,
  and no whole table is ever rendered into a <select> to achieve it.

  Enter picks the first row. Escape empties the box, then closes the list.
--}}
<div class="tb-picker" data-resource="{{ $resource }}"
     x-data
     @click.outside="$wire.closeList()">

  @if($selectedLabel !== null)
    <div class="tb-picker-selected">
      <span class="tb-picker-selected-label">{{ $selectedLabel }}</span>
      <x-ui.icon-button label="Change selection" icon="fa-rotate-left" wire:click="change" />
    </div>
  @else
    <div class="tb-search-wrap tb-picker-input">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" class="tb-input"
             wire:model.live.debounce.300ms="query"
             wire:focus="openList"
             @keydown.enter.prevent.stop="$wire.pickFirst()"
             @keydown.escape.prevent.stop="$wire.clearQuery()"
             placeholder="{{ $placeholder }}"
             autocomplete="off" role="combobox"
             aria-expanded="{{ $open ? 'true' : 'false' }}"
             aria-label="{{ $placeholder }}">
      <span wire:loading wire:target="query,openList" class="tb-picker-spinner">
        <i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i>
      </span>
    </div>

    {{-- At rest: the few picks most likely to be wanted, so the common case is
         one click. They are not repeated inside the list below — the list
         opens with the same rows at its head. --}}
    @if(! $open && $query === '' && $this->suggestions)
      <div class="tb-picker-pills">
        @foreach($this->suggestions as $pill)
          <button type="button" class="tb-pill" wire:key="sg-{{ $name }}-{{ $pill['id'] }}"
                  wire:click="pick({{ $pill['id'] }})"
                  title="{{ trim($pill['label'].' '.$pill['meta']) }}">
            {{ $pill['label'] }}
          </button>
        @endforeach
      </div>
    @endif

    @if($open && $query !== '')
      <ul class="tb-picker-results" role="listbox">
        @forelse($this->options as $opt)
          <li wire:key="opt-{{ $name }}-{{ $opt['id'] }}">
            <button type="button" class="tb-picker-option" role="option" aria-selected="false"
                    wire:click="pick({{ $opt['id'] }})">
              <span class="tb-picker-option-label">{{ $opt['label'] }}</span>
              @if($opt['meta'] !== '')<span class="muted mono tb-xs">{{ $opt['meta'] }}</span>@endif
            </button>
          </li>
        @empty
          <li class="tb-picker-none muted tb-xs">No matches for “{{ $query }}”.</li>
        @endforelse
      </ul>

    @elseif($open)
      @php($browse = $this->browse)
      <ul class="tb-picker-results is-browsing" role="listbox">
        @if($browse['likely'])
          <li class="tb-picker-group" role="presentation">Likely</li>
          @foreach($browse['likely'] as $opt)
            <li wire:key="lk-{{ $name }}-{{ $opt['id'] }}">
              <button type="button" class="tb-picker-option" role="option" aria-selected="false"
                      wire:click="pick({{ $opt['id'] }})">
                <span class="tb-picker-option-label">{{ $opt['label'] }}</span>
                @if($opt['meta'] !== '')<span class="muted mono tb-xs">{{ $opt['meta'] }}</span>@endif
              </button>
            </li>
          @endforeach
        @endif

        @if($browse['rest'])
          @if($browse['likely'])<li class="tb-picker-group" role="presentation">All</li>@endif
          @foreach($browse['rest'] as $opt)
            <li wire:key="br-{{ $name }}-{{ $opt['id'] }}">
              <button type="button" class="tb-picker-option" role="option" aria-selected="false"
                      wire:click="pick({{ $opt['id'] }})">
                <span class="tb-picker-option-label">{{ $opt['label'] }}</span>
                @if($opt['meta'] !== '')<span class="muted mono tb-xs">{{ $opt['meta'] }}</span>@endif
              </button>
            </li>
          @endforeach
        @endif

        @unless($browse['likely'] || $browse['rest'])
          {{-- Say what is actually wrong. "Nothing to choose from" is true of
               an empty catalogue and of a search that matched nothing, and the
               two want different things done about them. --}}
          <li class="tb-picker-none muted tb-xs">
            @if($query !== '')
              Nothing matches “{{ $query }}”.
            @else
              Nothing to choose from yet.
            @endif
          </li>
        @endunless
      </ul>
    @endif
  @endif
</div>
