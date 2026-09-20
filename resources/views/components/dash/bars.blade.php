@props([
    'data' => [],          // ['label' => numericValue, ...]
    'money' => false,      // format values as currency
    'labels' => [],        // optional ['key' => 'Nice Label']
])
@php
    $data = array_filter($data, fn ($v) => (float) $v > 0);
    $max = 0;
    foreach ($data as $v) { $max = max($max, (float) $v); }
    $fmt = fn ($v) => $money ? app(\App\Support\HospitalSettings::class)->format($v) : number_format((float) $v);
@endphp
@if(empty($data))
  <x-dash.empty text="No data for this period" />
@else
  <div class="tb-card-body"><div class="dash-bars">
    @foreach($data as $key => $val)
      <div class="dash-bar-row">
        <span class="bl" title="{{ $labels[$key] ?? $key }}">{{ $labels[$key] ?? str_replace('_', ' ', $key) }}</span>
        <span class="dash-bar-track"><span class="dash-bar-fill" style="width:{{ $max > 0 ? round((float) $val / $max * 100) : 0 }}%"></span></span>
        <span class="bv">{{ $fmt($val) }}</span>
      </div>
    @endforeach
  </div></div>
@endif
