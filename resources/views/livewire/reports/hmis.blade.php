<div>
  <x-ui.page-header title="Ministry reports (HMIS)" :crumbs="['Reports' => route('admin.reports.index'), 'HMIS' => null]">
    <x-slot:explain>
      Counted from your own records: each visit by its primary coded diagnosis, by the form's age bands and sex.
      Anything that cannot be counted is listed at the bottom with a link to fix it. {{ config('hmis.version') }}.
    </x-slot:explain>
  </x-ui.page-header>

  <div class="tb-filter-bar tb-toolbar">
    <select wire:model.live="form" class="tb-select" aria-label="Which form">
      @foreach($forms as $key => $f)<option value="{{ $key }}">{{ $f['label'] }}</option>@endforeach
    </select>
    @if($form === '033b')
      <input type="date" class="tb-input tb-input-slim" wire:model.live="week" aria-label="A day in the week">
      <span class="muted tb-small">Week {{ $report['week']['number'] }} of {{ $report['week']['year'] }}: {{ $report['week']['from'] }} to {{ $report['week']['to'] }}</span>
    @else
      <input type="month" class="tb-input tb-input-slim" wire:model.live="month" aria-label="Month">
    @endif
    <span class="tb-ml-auto">
      <a class="btn-tb btn-tb-sm" target="_blank" rel="noopener" href="{{ route('admin.reports.hmis.export', ['form' => $form, 'format' => 'pdf', 'month' => $month, 'week' => $week]) }}"><i class="fas fa-print" aria-hidden="true"></i> PDF</a>
      <a class="btn-tb btn-tb-sm" href="{{ route('admin.reports.hmis.export', ['form' => $form, 'format' => 'csv', 'month' => $month, 'week' => $week]) }}"><i class="fas fa-file-csv" aria-hidden="true"></i> CSV</a>
    </span>
  </div>

  <div wire:loading.class="tb-loading" wire:target="form,month,week">
  @if($form === '033b')
    <div class="tb-card"><div class="tb-table-wrap"><table class="tb-table">
      <thead><tr><th>Condition</th><th class="tb-text-right">Cases</th><th class="tb-text-right">Deaths</th></tr></thead>
      <tbody>
        @foreach($report['conditions'] as $c)
          <tr @class(['hmis-hit' => $c['cases'] > 0])><td>{{ $c['label'] }}</td><td class="tb-text-right mono">{{ $c['cases'] }}</td><td class="tb-text-right mono">{{ $c['deaths'] }}</td></tr>
        @endforeach
      </tbody>
    </table></div></div>
  @else
    @php($bands = $report['bands'])
    <div class="tb-card tb-mb-4"><div class="tb-table-wrap"><table class="tb-table hmis-grid">
      <thead>
        <tr><th rowspan="2">Line</th>@foreach($bands as $label)<th colspan="2" class="tb-text-center">{{ $label }}</th>@endforeach<th rowspan="2" class="tb-text-right">Total</th></tr>
        <tr>@foreach($bands as $label)<th class="tb-text-right">M</th><th class="tb-text-right">F</th>@endforeach</tr>
      </thead>
      <tbody>
        @foreach($rows as $label => $grid)
          @php($total = collect($grid)->sum(fn ($s) => $s['male'] + $s['female']))
          <tr @class(['hmis-zero' => $total === 0])>
            <td class="tb-small">{{ $label }}</td>
            @foreach(array_keys($bands) as $b)<td class="tb-text-right mono">{{ $grid[$b]['male'] ?: '' }}</td><td class="tb-text-right mono">{{ $grid[$b]['female'] ?: '' }}</td>@endforeach
            <td class="tb-text-right mono tb-fw-600">{{ $total }}</td>
          </tr>
        @endforeach
      </tbody>
    </table></div></div>
    @if($form === '105')
      <p class="muted tb-small tb-mb-4">All other diagnoses: {{ $report['other'] }}. Visits this month: {{ $report['total_visits'] }}.</p>
    @else
      <p class="muted tb-small tb-mb-4">Bed days: {{ $report['bed_days'] }}. Absconded: {{ $report['absconded'] }}.
        @foreach($report['diagnoses'] as $d) {{ $d['label'] }}: {{ $d['total'] }}.@endforeach</p>
    @endif

    <div class="tb-card">
      <div class="tb-card-header"><span class="tb-card-title"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> Missing data ({{ count($report['missing']) }})</span></div>
      @if($report['missing'] === [])
        <div class="tb-card-body muted tb-small">Nothing missing: every visit is counted.</div>
      @else
        <div class="tb-table-wrap"><table class="tb-table">
          <thead><tr><th>Visit</th><th>Patient</th><th>Missing</th><th></th></tr></thead>
          <tbody>
            @foreach(array_slice($report['missing'], 0, 100) as $m)
              <tr>
                <td class="mono tb-small">{{ $m['visit_no'] }}</td>
                <td class="tb-small">{{ $m['patient'] }}</td>
                <td class="tb-small">{{ implode(', ', $m['why']) }}</td>
                <td class="tb-text-right">
                  @if(in_array('no coded diagnosis', $m['why'], true) && $m['visit_uuid'] !== '')<a href="{{ route('admin.visits.show', $m['visit_uuid']) }}" wire:navigate class="tb-small">Code it</a>@endif
                  @if((in_array('no age', $m['why'], true) || in_array('no sex', $m['why'], true)) && $m['patient_uuid'])<a href="{{ route('admin.patients.edit', $m['patient_uuid']) }}" wire:navigate class="tb-small">Fix the patient</a>@endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table></div>
      @endif
    </div>
  @endif
  </div>
</div>
