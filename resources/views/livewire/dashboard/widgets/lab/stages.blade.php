@php $stageLabels = collect(\App\Enums\LabOrderStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all(); @endphp
<div>
  <x-dash.section title="Orders by stage" icon="fa-list-check">
    <x-dash.bars :data="$stages" :labels="$stageLabels" />
  </x-dash.section>
  <x-dash.section title="Recent abnormal results" icon="fa-triangle-exclamation">
    <div class="dash-queue">
      @forelse($abnormal as $item)
        <div class="dash-queue-row">
          <div class="qmain"><div class="qname">{{ $item->name }}</div>
            <div class="qmeta">Result: {{ $item->result_value }}</div></div>
          <span class="qtime tb-danger tb-fw-600">{{ $item->result_flag?->value }}</span>
        </div>
      @empty
        <x-dash.empty icon="fa-circle-check" text="No abnormal results" />
      @endforelse
    </div>
  </x-dash.section>
</div>
