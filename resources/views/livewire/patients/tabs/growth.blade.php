@php
  $a = $this->assessment;
  $d = fn ($v, $f = 'd M Y') => $v ? \Illuminate\Support\Carbon::parse($v)->format($f) : '—';
  $z = fn ($v) => \App\Services\GrowthService::z($v);
  $toneOf = fn ($band) => match ($band['tone'] ?? '') { 'danger' => 'bad', 'warn' => 'warn', 'success' => 'ok', default => '' };
@endphp
<div class="tb-stack">
  @if($a['faltering']['flag'] || $a['oedema'])
    <div class="tb-alert tb-alert-danger" role="status">
      <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
      <div>
        @if($a['faltering']['flag'])<b>Growth faltering.</b> {{ $a['faltering']['reason'] }}@endif
        @if($a['oedema'])<b>Bilateral oedema</b> at the last measurement: treat as severe acute malnutrition.@endif
      </div>
    </div>
  @endif

  <div class="tb-stats-grid" role="group" aria-label="Growth">
    <x-dash.stat icon="fa-weight-scale" label="Weight-for-age"
                 :value="$a['weight'] ? $a['weight']['value'].' kg' : '—'"
                 :sub="$a['weight'] ? ($a['weight']['band']['label'] ?? 'No WHO reading').' · z '.$z($a['weight']['z']) : 'Not weighed yet'"
                 :tone="$a['weight'] ? $toneOf($a['weight']['band']) : ''" />
    <x-dash.stat icon="fa-ruler-vertical" label="Length/height-for-age"
                 :value="$a['height'] ? $a['height']['value'].' cm' : '—'"
                 :sub="$a['height'] ? ($a['height']['band']['label'] ?? 'No WHO reading').' · z '.$z($a['height']['z']) : 'Not measured yet'"
                 :tone="$a['height'] ? $toneOf($a['height']['band']) : ''" />
    <x-dash.stat icon="fa-ring" label="MUAC"
                 :value="$a['muac'] ? $a['muac']['value'].' cm' : '—'"
                 :sub="$a['muac'] ? ($a['muac']['band']['label'] ?? 'Read from 6 to 59 months') : 'Not measured yet'"
                 :tone="$a['muac'] ? $toneOf($a['muac']['band']) : ''" />
  </div>

  <div class="tb-card">
    <div class="tb-card-header">
      <span class="tb-card-title"><i class="fas fa-chart-line" aria-hidden="true"></i> Weight-for-age</span>
      @if($canRecord)<button type="button" class="btn-tb btn-tb-primary btn-tb-sm" wire:click="openForm"><i class="fas fa-plus" aria-hidden="true"></i> Record measurement</button>@endif
    </div>
    <div class="tb-card-body">
      @if($chart && $chart['dots'])
        <svg class="mc-chart" viewBox="0 0 {{ $chart['w'] }} {{ $chart['h'] }}" role="img" aria-label="Weight-for-age against the WHO standards">
          @foreach($chart['yTicks'] as $t)
            <line class="mc-grid" x1="{{ $chart['pad'] }}" x2="{{ $chart['w'] - 8 }}" y1="{{ $t['y'] }}" y2="{{ $t['y'] }}" />
            <text class="mc-tick" x="{{ $chart['pad'] - 4 }}" y="{{ $t['y'] + 3 }}" text-anchor="end">{{ $t['label'] }}</text>
          @endforeach
          @foreach($chart['xTicks'] as $t)
            <text class="mc-tick" x="{{ $t['x'] }}" y="{{ $chart['base'] + 14 }}" text-anchor="middle">{{ $t['label'] }}</text>
          @endforeach
          @foreach($chart['lines'] as $l)
            <polyline class="mc-ref {{ $l['class'] }}" points="{{ $l['points'] }}" />
          @endforeach
          <polyline class="mc-child" points="{{ $chart['path'] }}" />
          @foreach($chart['dots'] as $dot)
            <circle class="mc-dot mc-dot-{{ $dot['tone'] }}" cx="{{ $dot['cx'] }}" cy="{{ $dot['cy'] }}" r="3.5"><title>{{ $dot['title'] }}</title></circle>
          @endforeach
        </svg>
        <p class="muted tb-xs">WHO Child Growth Standards: the middle line is the median, the dashed lines −2 and +2, the lowest −3. Below −2 is underweight.</p>
      @else
        <x-ui.empty icon="fa-chart-line" compact message="{{ $this->patient->dob === null || $this->patient->sex === null ? 'Record the date of birth and sex to read the chart.' : 'No weighings yet. Weights taken as vitals on visits appear here too.' }}" />
      @endif
    </div>
  </div>

  @if($this->points)
    <div class="tb-card">
      <div class="tb-card-header"><span class="tb-card-title">Measurements</span></div>
      <div class="tb-table-wrap"><table class="tb-table">
        <caption class="sr-only">Every measurement, newest first</caption>
        <thead><tr><th>Date</th><th>Age</th><th class="tb-text-right">Weight</th><th>WFA</th><th class="tb-text-right">Length/height</th><th>HFA</th><th class="tb-text-right">MUAC</th><th>From</th></tr></thead>
        <tbody>
          @foreach(array_reverse($this->points) as $p)
            <tr wire:key="gp-{{ $p['uuid'] }}">
              <td class="tb-small tb-nowrap">{{ $d($p['date']) }}</td>
              <td class="tb-small">{{ $p['age_label'] ?? '—' }}</td>
              <td class="tb-text-right mono">{{ $p['weight'] ?? '—' }}</td>
              <td>@if($p['weight_band'])<x-ui.badge :tone="$p['weight_band']['tone']">{{ $z($p['weight_z']) }}</x-ui.badge>@endif</td>
              <td class="tb-text-right mono">{{ $p['height'] ?? '—' }}</td>
              <td>@if($p['height_band'])<x-ui.badge :tone="$p['height_band']['tone']">{{ $z($p['height_z']) }}</x-ui.badge>@endif</td>
              <td class="tb-text-right mono">{{ $p['muac'] ?? '—' }}@if($p['muac_band']) <x-ui.badge :tone="$p['muac_band']['tone']">{{ $p['muac_band']['key'] === 'normal' ? 'OK' : ucfirst($p['muac_band']['key']) }}</x-ui.badge>@endif</td>
              <td class="tb-xs muted">{{ $p['source'] === 'visit' ? 'Visit vitals' : 'Child clinic' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table></div>
    </div>
  @endif

  <x-ui.modal show="showForm" size="sm" title="Record a measurement">
    @if($showForm)
      <form wire:submit="save" class="mc-form">
        <div class="tb-modal-body">
          <x-ui.field label="Measured on" for="gr-on" name="measuredOn"><input id="gr-on" type="date" class="tb-input" wire:model="measuredOn" max="{{ now()->toDateString() }}"></x-ui.field>
          <div class="tb-form-grid">
            <x-ui.field label="Weight (kg)" for="gr-w" name="weight"><input id="gr-w" type="number" step="0.01" class="tb-input" wire:model="weight"></x-ui.field>
            <x-ui.field label="Length/height (cm)" for="gr-h" name="height" hint="Lying under two years."><input id="gr-h" type="number" step="0.1" class="tb-input" wire:model="height"></x-ui.field>
            <x-ui.field label="MUAC (cm)" for="gr-m" name="muac"><input id="gr-m" type="number" step="0.1" class="tb-input" wire:model="muac"></x-ui.field>
          </div>
          <label class="tb-check-group tb-xs"><input type="checkbox" wire:model="oedema"> Bilateral pitting oedema</label>
          <x-ui.field label="Notes" for="gr-n" name="notes"><input id="gr-n" type="text" class="tb-input" wire:model="notes" maxlength="500"></x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="$set('showForm', false)">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">Record</button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
