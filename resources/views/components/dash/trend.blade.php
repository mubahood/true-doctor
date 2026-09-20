@props([
    'billed' => [],        // ['Y-m-d' => numeric] ordered
    'collected' => [],     // the same days
    'billedTotal' => '0',
    'collectedTotal' => '0',
])
@php
    $money = app(\App\Support\HospitalSettings::class);

    $days = array_keys($billed);
    $n = max(count($days), 1);

    $peak = 0.0;
    foreach (array_merge(array_values($billed), array_values($collected)) as $v) {
        $peak = max($peak, (float) $v);
    }

    // A round number to draw the ceiling at, so the gridline means something a
    // reader can hold: 657,700 tops out at 700,000, not at itself.
    $ceiling = 0.0;
    if ($peak > 0) {
        $magnitude = 10 ** max(0, (int) floor(log10($peak)));
        $ceiling = ceil($peak / $magnitude) * $magnitude;
    }

    $gap = bcsub((string) $billedTotal, (string) $collectedTotal, 2);
    $height = 118;

    // An axis label is read at a glance, so it is short: 700k, not USh700,000.
    // The exact figures are on the legend and on every bar's tooltip.
    $axis = function (float $v) {
        if ($v >= 1_000_000) { return rtrim(rtrim(number_format($v / 1_000_000, 1), '0'), '.').'M'; }
        if ($v >= 1_000)     { return rtrim(rtrim(number_format($v / 1_000, 1), '0'), '.').'k'; }
        return (string) (int) $v;
    };
@endphp
<div class="tb-card-body">
  @if($peak <= 0)
    <x-dash.empty icon="fa-chart-column" text="Nothing billed or collected in this window" />
  @else
    {{--
      BARS, NOT LINES. These are daily totals — discrete events, not a
      continuous quantity — and a line between them draws money on days when
      none moved. Fourteen mostly-quiet days became a flat line hugging the
      floor with one spike, which reads as a broken chart rather than as a
      quiet fortnight.

      Billed is the pale bar behind; collected is the solid one in front. Where
      the pale shows above the solid, that day was billed and not paid.
    --}}
    <div class="dash-chart" style="--h:{{ $height }}px;">
      <div class="dash-chart-y" aria-hidden="true">
        <span>{{ $axis($ceiling) }}</span>
        <span>{{ $axis($ceiling / 2) }}</span>
        <span>0</span>
      </div>

      <div class="dash-chart-plot">
        <span class="dash-grid-line" style="bottom:100%"></span>
        <span class="dash-grid-line" style="bottom:50%"></span>
        <span class="dash-grid-line base" style="bottom:0"></span>

        @foreach($days as $day)
          @php
              $b = (float) ($billed[$day] ?? 0);
              $c = (float) ($collected[$day] ?? 0);
              $label = \Illuminate\Support\Carbon::parse($day)->format('D d M');
          @endphp
          <div class="dash-col"
               title="{{ $label }} · billed {{ $money->format((string) $b) }} · collected {{ $money->format((string) $c) }}">
            <span class="dash-col-billed" style="height:{{ $ceiling > 0 ? max(1.5, $b / $ceiling * 100) : 0 }}%"></span>
            <span class="dash-col-collected" style="height:{{ $ceiling > 0 ? max($c > 0 ? 1.5 : 0, $c / $ceiling * 100) : 0 }}%"></span>
          </div>
        @endforeach
      </div>
    </div>

    <div class="dash-chart-x">
      <span>{{ \Illuminate\Support\Carbon::parse($days[0])->format('d M') }}</span>
      <span>{{ \Illuminate\Support\Carbon::parse($days[intdiv($n, 2)])->format('d M') }}</span>
      <span>{{ \Illuminate\Support\Carbon::parse(end($days))->format('d M') }}</span>
    </div>

    <div class="dash-legend-inline">
      <span><i class="dash-key solid"></i> Collected <b>{{ $money->format($collectedTotal) }}</b></span>
      <span><i class="dash-key pale"></i> Billed <b>{{ $money->format($billedTotal) }}</b></span>
      @if(bccomp($gap, '0', 2) > 0)
        <span class="dash-gap">Unpaid <b>{{ $money->format($gap) }}</b></span>
      @endif
    </div>
  @endif
</div>
