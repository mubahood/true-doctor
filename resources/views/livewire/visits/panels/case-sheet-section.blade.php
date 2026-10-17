@php
  $def = $this->definition;
  $row = $this->row;
  $patientScope = $def['scope'] === 'patient';
  $hasNormal = ! empty($def['normal']);
  $isNormal = ! empty($row['data']['normal']);
  $canWrite = $this->canWrite();
@endphp
<div class="cs-section" id="cs-{{ $section }}">
  <div class="cs-head">
    <span class="cs-title">{{ $def['label'] }}</span>
    @if($patientScope && $row['version'] > 0)
      @if($row['asked_this_visit'])
        <x-ui.badge tone="success">Checked on this visit</x-ui.badge>
      @else
        <span class="muted tb-xs">{{ $row['confirmed'] ? 'Confirmed' : 'Updated' }} {{ \Illuminate\Support\Carbon::parse($row['recorded_at'])->format('d M Y') }}@if($row['recorded_by']) · {{ $row['recorded_by'] }}@endif</span>
      @endif
    @endif
    @if($canWrite && ! $editing)
      <span class="cs-acts">
        @if($hasNormal && ! $isNormal)
          <button type="button" class="btn-tb btn-tb-sm" wire:click="normal" wire:loading.attr="disabled" wire:target="normal"><i class="fas fa-check" aria-hidden="true"></i> Normal</button>
        @endif
        @if($patientScope && ! $row['asked_this_visit'] && ! in_array($row['data']['pregnancy'] ?? null, ['pregnant', 'breastfeeding'], true))
          <button type="button" class="btn-tb btn-tb-sm" wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm">
            <i class="fas fa-check" aria-hidden="true"></i> {{ $row['version'] > 0 ? 'Still correct' : 'Nothing to report' }}
          </button>
        @endif
        <button type="button" class="btn-tb btn-tb-sm btn-tb-ghost" wire:click="edit" wire:loading.attr="disabled" wire:target="edit">
          <i class="fas {{ $row['version'] > 0 ? 'fa-pen' : 'fa-plus' }}" aria-hidden="true"></i> {{ $row['version'] > 0 ? 'Edit' : 'Write' }}
        </button>
      </span>
    @endif
  </div>

  @if(! $editing)
    @if($this->money)
      @php($m = $this->money)
      <dl class="cs-lines">
        <dt>Owes from before</dt>
        <dd>@if(bccomp($m['outstanding'], '0', 2) > 0)<b>{{ $m['outstanding_label'] }}</b>@if($m['oldest_unpaid_days'] !== null)<span class="muted"> · oldest unpaid {{ $m['oldest_unpaid_days'] }} days</span>@endif @else Nothing @endif</dd>
        <dt>Usually pays</dt><dd>{{ $m['usual_method'] ?? 'No payments yet' }}@if($m['last_payments'])<span class="muted"> · last {{ $m['last_payments'][0]['amount_label'] }} on {{ \Illuminate\Support\Carbon::parse($m['last_payments'][0]['date'])->format('d M Y') }}</span>@endif</dd>
        @if($m['insurance'])<dt>Insurance</dt><dd>{{ implode(', ', $m['insurance']) }}</dd>@endif
        @if($m['accepted_quote'])<dt>Accepted plan</dt><dd>{{ $m['accepted_quote']['total_label'] }} <span class="muted">· {{ $m['accepted_quote']['items'] }} {{ \Illuminate\Support\Str::plural('procedure', $m['accepted_quote']['items']) }} still to do</span></dd>@endif
      </dl>
    @endif

    @if($lines !== [])
      <dl class="cs-lines">
        @foreach($lines as $line)
          <dt>{{ $line['label'] }}</dt><dd>{!! nl2br(e($line['value'])) !!}</dd>
        @endforeach
      </dl>
    @elseif(! $this->money)
      <p class="muted tb-xs cs-empty">{{ $patientScope ? 'Not asked yet.' : 'Not written yet.' }}</p>
    @endif
  @else
    <form wire:submit="save" class="cs-form">
      @error('form')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror

      @if($hasNormal)
        <label class="tb-check-group"><input type="checkbox" wire:model.live="form.normal"> Normal</label>
      @endif

      <div class="tb-form-grid">
        @foreach($def['fields'] as $key => $field)
          @continue($hasNormal && ! empty($form['normal']) && ! empty($field['abnormal']))
          @continue(! empty($field['women']) && ! $asksPregnancy)
          @php($id = 'cs-'.$section.'-'.$key)
          @php($wide = in_array($field['type'], ['chips', 'textarea'], true) || ($field['type'] === 'choice' && count($field['options']) > 3))
          <x-ui.field :label="$field['label']" :for="in_array($field['type'], ['chips', 'choice'], true) ? null : $id" :name="'form.'.$key" :full="$wide" :hint="$field['hint'] ?? null">
            @switch($field['type'])
              @case('chips')
                <div class="cs-chips" role="group" aria-label="{{ $field['label'] }}">
                  @foreach($field['options'] as $value => $label)
                    <label class="tb-chip-check" wire:key="{{ $id }}-{{ $value }}"><input type="checkbox" value="{{ $value }}" wire:model="form.{{ $key }}"> {{ $label }}</label>
                  @endforeach
                </div>
                @break
              @case('choice')
                <div class="cs-chips" role="radiogroup" aria-label="{{ $field['label'] }}">
                  @foreach($field['options'] as $value => $label)
                    <label class="tb-chip-check" wire:key="{{ $id }}-{{ $value }}"><input type="radio" name="{{ $id }}" value="{{ $value }}" wire:model="form.{{ $key }}"> {{ $label }}</label>
                  @endforeach
                </div>
                @break
              @case('textarea')
                <textarea id="{{ $id }}" class="tb-textarea" rows="2" maxlength="2000" wire:model="form.{{ $key }}"></textarea>
                @break
              @default
                <input id="{{ $id }}" type="text" class="tb-input" maxlength="500" wire:model="form.{{ $key }}"
                       @if(! empty($field['placeholder'])) placeholder="{{ $field['placeholder'] }}" @endif
                       @if(! empty($field['suggest'])) list="{{ $id }}-list" @endif>
                @if(! empty($field['suggest']))
                  <datalist id="{{ $id }}-list">@foreach($field['suggest'] as $s)<option value="{{ $s }}"></option>@endforeach</datalist>
                @endif
            @endswitch
          </x-ui.field>
        @endforeach
      </div>

      <div class="tb-flex">
        <button type="submit" class="btn-tb btn-tb-primary btn-tb-sm" wire:loading.attr="disabled" wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> Save</button>
        <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm" wire:click="cancel">Cancel</button>
      </div>
    </form>
  @endif
</div>
