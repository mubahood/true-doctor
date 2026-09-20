@props(['value' => 0, 'max' => 100, 'label' => 'Progress', 'tone' => 'primary', 'showValue' => true])
{{-- Accessible progress bar. The value is announced by the role, and also shown
     as text so it never depends on colour alone. --}}
@php
    $max = max(1, (int) $max);
    $value = max(0, min((int) $value, $max));
    $percent = (int) round($value / $max * 100);
@endphp
<div {{ $attributes->merge(['class' => 'tb-progress-wrap']) }}>
  <div class="tb-progress-meta">
    <span class="tb-progress-label">{{ $label }}</span>
    @if($showValue)<span class="tb-progress-value">{{ $value }} of {{ $max }} · {{ $percent }}%</span>@endif
  </div>
  <div class="tb-progress" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $max }}"
       aria-valuenow="{{ $value }}" aria-valuetext="{{ $value }} of {{ $max }} complete" aria-label="{{ $label }}">
    <div class="tb-progress-bar tone-{{ $tone }}" style="width:{{ $percent }}%"></div>
  </div>
</div>
