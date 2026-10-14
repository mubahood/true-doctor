{{-- The move between two figures, coloured by whether it is good news. A cost going up is not. --}}
@php($diff = bcsub((string) $now, (string) $then, 2))
@php($up = bccomp($diff, '0', 2) > 0)
@php($good = $cost ? ! $up : $up)
@if(bccomp($diff, '0', 2) === 0)
  <span class="muted">—</span>
@else
  <span @class(['fn-up' => $good, 'fn-down' => ! $good])>
    {{ $up ? '+' : '−' }}{{ \App\Support\HospitalSettings::money(ltrim($diff, '-')) }}
    @if(bccomp((string) $then, '0', 2) !== 0)<small>({{ $up ? '+' : '−' }}{{ number_format(abs((float) $diff / (float) $then * 100), 0) }}%)</small>@endif
  </span>
@endif
