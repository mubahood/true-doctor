@props([
    'series' => [],         // ['Y-m-d' => numericValue] ordered
    'money' => true,
])
@php
    $vals = array_values($series);
    $keys = array_keys($series);
    $n = count($vals);
    $max = 0;
    foreach ($vals as $v) { $max = max($max, (float) $v); }
    $w = 300; $h = 56; $pad = 4;
    $pts = [];
    foreach ($vals as $idx => $v) {
        $x = $n > 1 ? $pad + $idx / ($n - 1) * ($w - 2 * $pad) : $w / 2;
        $y = $max > 0 ? $h - $pad - ((float) $v / $max) * ($h - 2 * $pad) : $h - $pad;
        $pts[] = round($x, 1).','.round($y, 1);
    }
    $line = implode(' ', $pts);
    $area = $n > 0 ? "{$pad},".($h - $pad).' '.$line.' '.($w - $pad).','.($h - $pad) : '';
    $total = array_sum(array_map('floatval', $vals));
    $fmt = fn ($v) => $money ? app(\App\Support\HospitalSettings::class)->format($v) : number_format((float) $v);
@endphp
<div class="tb-card-body">
  @if($total <= 0)
    <x-dash.empty icon="fa-chart-line" text="No activity in this window" />
  @else
    <svg class="dash-spark" viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none" role="img" aria-label="Trend">
      <polygon points="{{ $area }}" fill="var(--br-soft)" />
      <polyline points="{{ $line }}" fill="none" stroke="var(--br)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
    </svg>
    <div class="dash-spark-foot">
      <span>{{ \Illuminate\Support\Carbon::parse($keys[0])->format('d M') }}</span>
      <span>Total {{ $fmt($total) }}</span>
      <span>{{ \Illuminate\Support\Carbon::parse(end($keys))->format('d M') }}</span>
    </div>
  @endif
</div>
