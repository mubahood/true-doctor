<div class="cs-section">
  <div class="cs-head">
    <span class="cs-title">Investigations</span>
    @if($canWrite && ! $adding)
      <span class="cs-acts">
        <button type="button" class="btn-tb btn-tb-sm btn-tb-ghost" wire:click="open"><i class="fas fa-x-ray" aria-hidden="true"></i> X-ray taken</button>
      </span>
    @endif
  </div>

  @if($this->xrays !== [])
    <ul class="dx-list">
      @foreach($this->xrays as $x)
        <li class="dx-item" wire:key="xr-{{ $x['uuid'] }}">
          <span class="mono cs-tooth">{{ $x['teeth'] ? implode(' ', $x['teeth']) : 'Mouth' }}</span>
          <span class="dx-title"><b>{{ $x['kind_label'] }}</b> · {{ $x['finding'] }}</span>
          @if($x['image'])<a href="{{ $x['image']['url'] }}" target="_blank" rel="noopener" class="btn-tb btn-tb-sm btn-tb-ghost"><i class="fas fa-image" aria-hidden="true"></i> Image</a>@endif
          @if($x['billed'])<x-ui.badge tone="info">Billed</x-ui.badge>@endif
        </li>
      @endforeach
    </ul>
  @endif

  @if($adding)
    <form wire:submit="save" class="cs-form">
      <div class="cs-chips" role="radiogroup" aria-label="Kind of X-ray">
        @foreach($kinds as $value => $label)
          <label class="tb-chip-check" wire:key="xk-{{ $value }}"><input type="radio" name="xr-kind" value="{{ $value }}" wire:model.live="kind"> {{ $label }}</label>
        @endforeach
      </div>
      @error('kind')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
      <div class="tb-form-grid">
        <x-ui.field label="Teeth" for="xr-teeth" name="teeth" :hint="$kind === 'opg' ? 'Leave empty for the whole mouth.' : 'Separate with spaces, for example 35 36.'">
          <input id="xr-teeth" type="text" class="tb-input" wire:model="teeth" maxlength="60" placeholder="35 36">
        </x-ui.field>
        <x-ui.field label="Charge" for="xr-service" name="serviceId">
          <select id="xr-service" class="tb-select" wire:model="serviceId">
            <option value="">Not charged</option>
            @foreach($this->services as $svc)<option value="{{ $svc->id }}">{{ $svc->name }} · {{ $svc->priceLabel() }}</option>@endforeach
          </select>
        </x-ui.field>
        <x-ui.field label="Finding" for="xr-finding" name="finding" :full="true">
          <input id="xr-finding" type="text" class="tb-input" wire:model="finding" maxlength="500" placeholder="For example: radiolucency close to the pulp, no periapical change">
        </x-ui.field>
        <x-ui.field label="Image (optional)" for="xr-image" name="image" :full="true" hint="JPG, PNG, WebP or PDF, up to 10 MB.">
          <input id="xr-image" type="file" class="tb-input" wire:model="image" accept="image/*,application/pdf">
        </x-ui.field>
      </div>
      <div class="tb-flex">
        <button type="submit" class="btn-tb btn-tb-primary btn-tb-sm" wire:loading.attr="disabled" wire:target="save,image"><i class="fas fa-check" aria-hidden="true"></i> Record</button>
        <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm" wire:click="$set('adding', false)">Cancel</button>
      </div>
    </form>
  @endif

  @if($this->labs !== [])
    <dl class="cs-lines">
      @foreach($this->labs as $lab)
        <dt>{{ $lab['name'] }}</dt>
        <dd>{{ $lab['result'] !== null ? trim($lab['result'].' '.($lab['unit'] ?? '')) : $lab['status'] }}@if($lab['flag'])<span class="muted"> · {{ $lab['flag'] }}</span>@endif</dd>
      @endforeach
    </dl>
  @elseif($this->xrays === [] && ! $adding)
    <p class="muted tb-xs cs-empty">No X-ray or lab test on this visit.</p>
  @endif
</div>
