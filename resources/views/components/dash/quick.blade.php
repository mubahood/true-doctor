@props(['href', 'icon' => 'fa-arrow-right', 'label'])
<a href="{{ $href }}" wire:navigate class="dash-action">
  <i class="fas {{ $icon }}"></i><span>{{ $label }}</span>
</a>
