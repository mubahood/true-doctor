@props([
    'value',
    'label',
    'icon' => 'fa-chart-simple',
    'tone' => '',          // '', ok, warn, bad
    'sub' => null,
    'href' => null,
])
@php $tag = $href ? 'a' : 'div'; @endphp
<{{ $tag }} @if($href) href="{{ $href }}" wire:navigate @endif {{ $attributes->merge(['class' => 'tb-stat-card']) }}>
  <div class="tb-stat-icon {{ $tone ? 'tone-'.$tone : '' }}"><i class="fas {{ $icon }}"></i></div>
  <div>
    <div class="tb-stat-value {{ in_array($tone, ['warn','bad']) ? 'tone-'.$tone : '' }}">{{ $value }}</div>
    <div class="tb-stat-label">{{ $label }}</div>
    @if($sub !== null)<div class="tb-stat-sub">{{ $sub }}</div>@endif
  </div>
</{{ $tag }}>
