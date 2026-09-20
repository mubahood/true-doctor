<div class="tb-card">
  <div class="tb-card-header">
    <span class="tb-card-title"><i class="fas fa-heart-pulse" aria-hidden="true"></i> Vitals</span>
    @can('recordVitals', $this->visit)
      <button type="button" class="btn-tb btn-tb-sm" wire:click="openForm">
        <i class="fas {{ $this->visit->vitals_recorded_at ? 'fa-pen' : 'fa-plus' }}" aria-hidden="true"></i>
        {{ $this->visit->vitals_recorded_at ? 'Edit vitals' : 'Record vitals' }}
      </button>
    @endcan
  </div>

  {{-- What was measured. Vitals are read far more often than written, so this
       is the panel; recording them opens the dialog below. --}}
  <div class="tb-card-body">
    @if($this->visit->vitals_recorded_at)
      {{-- A strip, not a table: eight numbers should cost one band, not eight
           full-width rows. --}}
      @php
        $vitals = array_filter([
            'Temp' => $this->visit->temperature ? $this->visit->temperature.'°C' : null,
            'BP' => $this->visit->blood_pressure,
            'Pulse' => $this->visit->pulse,
            'SpO₂' => $this->visit->spo2 !== null ? $this->visit->spo2.'%' : null,
            'Resp' => $this->visit->respiratory_rate,
            'Weight' => $this->visit->weight ? rtrim(rtrim((string) $this->visit->weight, '0'), '.').'kg' : null,
            'Height' => $this->visit->height ? rtrim(rtrim((string) $this->visit->height, '0'), '.').'cm' : null,
            'BMI' => $this->visit->bmi,
        ], fn ($v) => $v !== null && $v !== '');
      @endphp
      <div class="tb-vitals-strip">
        @foreach($vitals as $label => $value)
          <div><span>{{ $label }}</span><b>{{ $value }}</b></div>
        @endforeach
      </div>
    @else
      <x-ui.empty compact icon="fa-heart-pulse" message="No vitals recorded on this visit." />
    @endif
  </div>

  {{-- ── Recording them ─────────────────────────────────────────── --}}
  <x-ui.modal size="md" show="showForm" title="Record vitals">
    @if($showForm)
      <form wire:submit="save" style="display:contents;">
        <div class="tb-modal-body">
          @php
            $vitalFields = [
              ['temperature', 'Temp °C', 'v-temperature', 'number', '0.1', null],
              ['blood_pressure', 'Blood pressure', 'v-bp', 'text', null, '120/80'],
              ['weight', 'Weight kg', 'v-weight', 'number', 'any', null],
              ['height', 'Height cm', 'v-height', 'number', 'any', null],
              ['pulse', 'Pulse', 'v-pulse', 'number', null, null],
              ['spo2', 'SpO₂ %', 'v-spo2', 'number', null, null],
              ['respiratory_rate', 'Resp. rate', 'v-rr', 'number', null, null],
            ];
            $suggestions = $this->suggestions;
          @endphp

          <div class="tb-grid-2">
            @foreach($vitalFields as [$name, $label, $id, $inputType, $step, $placeholder])
              <x-ui.field :label="$label" :for="$id" :name="$name">
                <input id="{{ $id }}" type="{{ $inputType }}" class="tb-input"
                       @if($step) step="{{ $step }}" @endif
                       @if($placeholder) placeholder="{{ $placeholder }}" @endif
                       wire:model.blur="{{ $name }}">

                {{-- What this patient was last measured at, then readings
                     spread across the range — never four ways of saying
                     normal, which would invite accepting one unmeasured. --}}
                @if($suggestions[$name] ?? false)
                  <div class="tb-picker-pills tb-vital-pills">
                    @foreach($suggestions[$name] as $pill)
                      <button type="button"
                              @class(['tb-pill', 'is-last' => $pill['last'],
                                      'is-on' => (string) $$name === $pill['value']])
                              wire:key="vs-{{ $name }}-{{ $loop->index }}"
                              wire:click="useVital('{{ $name }}', @js($pill['value']))">
                        @if($pill['last'])<i class="fas fa-clock-rotate-left" aria-hidden="true"></i>@endif
                        {{ $pill['label'] }}
                      </button>
                    @endforeach
                  </div>
                @endif
              </x-ui.field>
            @endforeach
          </div>

          <div class="tb-flex tb-mt-3">
            <span class="tb-label">BMI</span>
            <strong aria-live="polite">{{ $this->bmi !== null ? number_format($this->bmi, 2) : '—' }}</strong>
          </div>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="$set('showForm', false)">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> Save vitals</span>
            <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
