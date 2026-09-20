@props([
    'data' => [],            // ['label' => value]
    'center' => null,        // what goes in the hole; defaults to the total
    'centerLabel' => 'Total',
    'sub' => null,           // one line under the legend
    'labels' => [],          // optional pretty labels
    'zeroLabel' => null,     // the segment to draw when everything is zero
])
@php
    $palette = ['#0a6ebd', '#15803d', '#b45309', '#7c3aed', '#0891b2', '#be123c', '#64748b'];

    $shown = array_filter($data, fn ($v) => (int) $v > 0);
    $total = array_sum($data);      // the WHOLE, including the empty parts
    $drawn = array_sum($shown);

    $stops = [];
    $legend = [];
    $acc = 0.0;
    $i = 0;

    foreach ($shown as $key => $val) {
        $color = $palette[$i % count($palette)];
        $start = $drawn > 0 ? $acc / $drawn * 100 : 0;
        $acc += $val;
        $end = $drawn > 0 ? $acc / $drawn * 100 : 0;

        $stops[] = "{$color} {$start}% {$end}%";
        $legend[] = [
            'label' => $labels[$key] ?? str_replace('_', ' ', (string) $key),
            'value' => $val,
            'share' => $drawn > 0 ? round($val / $drawn * 100) : 0,
            'color' => $color,
        ];
        $i++;
    }

    $gradient = $drawn > 0 ? 'conic-gradient('.implode(',', $stops).')' : 'var(--surface-2)';
@endphp
<div class="tb-card-body">
  @if($total === 0)
    <x-dash.empty text="{{ $zeroLabel ?? 'No data yet' }}" />
  @else
    <div class="dash-donut-wrap">
      <div class="dash-donut" style="background:{{ $gradient }};">
        <div class="dash-donut-center">
          <span class="dn">{{ $center ?? number_format($total) }}</span>
          <span class="dl">{{ $centerLabel }}</span>
        </div>
      </div>

      {{-- Each row carries its share as well as its count: "24" alone is a
           number, "24 · 100%" is a reading. --}}
      <div class="dash-legend">
        @foreach($legend as $row)
          <div class="dash-legend-row">
            <span class="dash-legend-dot" style="background:{{ $row['color'] }};"></span>
            <span class="ll">{{ $row['label'] }}</span>
            <span class="lv">{{ number_format($row['value']) }}<em>{{ $row['share'] }}%</em></span>
          </div>
        @endforeach

        @if($sub)<p class="dash-legend-sub">{{ $sub }}</p>@endif
      </div>
    </div>
  @endif
</div>
