<div>
  {{-- ── 1. The kind of practice ─────────────────────────────────────── --}}
  <span class="tb-label" id="px-sector-label">Which is closest to yours?</span>
  <div class="px-grid" role="radiogroup" aria-labelledby="px-sector-label">
    @foreach($this->sectors as $key => $option)
      <button type="button" role="radio" aria-checked="{{ $sector === $key ? 'true' : 'false' }}"
              @class(['px-card', 'is-on' => $sector === $key]) wire:click="pickSector('{{ $key }}')" wire:key="px-sector-{{ $key }}">
        <i class="fas {{ $option->icon }} px-card-icon" aria-hidden="true"></i>
        <span class="px-card-title">{{ $option->label }}</span>
        <span class="px-card-sum">{{ $option->summary }}</span>
        @if($sector === $key)<i class="fas fa-circle-check px-card-tick" aria-hidden="true"></i>@endif
      </button>
    @endforeach
  </div>
  @error('sector')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror

  @if($chosenSector)
    {{-- ── 2. What else it offers ────────────────────────────────────── --}}
    <div class="tb-mt-4">
      <span class="tb-label">What you offer</span>
      <p class="muted tb-small tb-mb-2">A {{ Str::lower($chosenSector->label) }} comes with these. Add anything else you do — a clinic with a dental chair, a maternity home with an ultrasound.</p>
      <div class="px-chips">
        @foreach($chosenSector->packs as $key)
          <span class="px-chip is-fixed" wire:key="px-own-{{ $key }}"><i class="fas {{ $packCatalogue[$key]::icon() }}" aria-hidden="true"></i> {{ $packCatalogue[$key]::label() }}</span>
        @endforeach
        @foreach($this->addable as $key)
          @php($on = in_array($key, $packs, true))
          <button type="button" @class(['px-chip', 'is-on' => $on]) aria-pressed="{{ $on ? 'true' : 'false' }}"
                  wire:click="togglePack('{{ $key }}')" wire:key="px-add-{{ $key }}" title="{{ $packCatalogue[$key]::summary() }}">
            <i class="fas {{ $on ? 'fa-check' : 'fa-plus' }}" aria-hidden="true"></i> {{ $packCatalogue[$key]::label() }}
          </button>
        @endforeach
      </div>

      @if($this->counts !== [])
        <p class="muted tb-xs tb-mt-2">
          Your starter set: {{ $this->counts['services'] }} priced services,
          {{ $this->counts['departments'] }} departments, {{ $this->counts['rooms'] }} rooms
          @if($this->counts['lab_tests']), {{ $this->counts['lab_tests'] }} lab tests @endif
          @if($this->counts['radiology']), {{ $this->counts['radiology'] }} imaging studies @endif
          @if($this->counts['stock_items']), {{ $this->counts['stock_items'] }} stock items @endif
          @if($this->counts['wards']), {{ $this->counts['wards'] }} wards @endif.
        </p>
      @endif
    </div>

    {{-- ── 3. The parts of the system it uses ────────────────────────── --}}
    <div class="tb-mt-4">
      <span class="tb-label">Parts of the system you'll use</span>
      <p class="muted tb-small tb-mb-2">
        What you leave off is kept out of your menu, so nobody has to scroll past what you never do. You can switch any of it back on later.
        @if($modulesTouched)<button type="button" class="tb-linkbtn" wire:click="resetModules">Use the suggested set</button>@endif
      </p>
      <div class="px-mods">
        @foreach($moduleCatalogue as $key => $module)
          @php($on = in_array($key, $modules, true))
          <button type="button" @class(['px-mod', 'is-on' => $on]) role="switch" aria-checked="{{ $on ? 'true' : 'false' }}"
                  wire:click="toggleModule('{{ $key }}')" wire:key="px-mod-{{ $key }}">
            <i class="fas {{ $module['icon'] }}" aria-hidden="true"></i>
            <span><b>{{ $module['label'] }}</b><small>{{ $module['hint'] }}</small></span>
            <span class="px-switch" aria-hidden="true"></span>
          </button>
        @endforeach
      </div>
    </div>

    <div class="wz-actions">
      <button type="button" wire:click="save" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save" @disabled(! $this->dirty)>
        <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> {{ $this->saved->chosen ? 'Save changes' : 'This is my practice' }}</span>
        <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
      </button>
      @if(! $this->dirty)<span class="muted tb-small"><i class="fas fa-circle-check" aria-hidden="true"></i> Saved</span>@endif
    </div>
  @endif

  {{-- ── 4. Set it all up ─────────────────────────────────────────────── --}}
  @if($this->saved->chosen && $this->preview !== [])
    <div class="px-apply tb-mt-4">
      <div class="px-apply-head">
        <i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i>
        <div>
          <b>Set up the rest in one go</b>
          <p class="muted tb-small">From your {{ Str::lower($this->saved->sector->label) }} template. Only what you don't have yet is added — nothing you already have is changed, and every price stays editable.</p>
        </div>
      </div>
      @if($this->dirty)
        <p class="tb-small px-apply-note"><i class="fas fa-circle-info" aria-hidden="true"></i> Save your changes above first — this follows what is saved.</p>
      @endif
      <div class="px-parts">
        @foreach($this->preview as $key => $part)
          <label class="px-part" wire:key="px-part-{{ $key }}">
            <input type="checkbox" value="{{ $key }}" wire:model="parts" @disabled($key !== 'accounts' && $part['new'] === 0)>
            <span class="px-part-name">{{ $part['label'] }}</span>
            <span class="px-part-count">
              @if($key === 'accounts')
                standard chart{{ $part['total'] ? ' + '.$part['total'].' for your practice' : '' }}
              @elseif($part['new'] === 0)
                <i class="fas fa-check" aria-hidden="true"></i> all set
              @else
                {{ $part['new'] }} to add
              @endif
            </span>
          </label>
        @endforeach
      </div>
      <div class="wz-actions">
        <button type="button" wire:click="apply" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="apply" @disabled($this->dirty)>
          <span wire:loading.remove wire:target="apply"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i> Set it up</span>
          <span wire:loading wire:target="apply"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Setting up…</span>
        </button>
        <span class="muted tb-small">Opening stock goes into the stock ledger and your books as stock you already owned.</span>
      </div>
    </div>
  @endif
</div>
