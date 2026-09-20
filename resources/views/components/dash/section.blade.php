@props([
    'title',
    'icon' => 'fa-list',
    'count' => null,
    'href' => null,        // "view all" link
    'viewLabel' => 'View all',
])
<div class="tb-card dash-section">
  <div class="tb-card-header">
    <span class="tb-card-title"><i class="fas {{ $icon }} muted"></i> {{ $title }}
      @if($count !== null)<span class="dash-count-badge" style="margin-left:6px;">{{ $count }}</span>@endif
    </span>
    @if($href)<a href="{{ $href }}" wire:navigate class="btn-tb btn-tb-sm btn-tb-ghost">{{ $viewLabel }} <i class="fas fa-arrow-right"></i></a>@endif
  </div>
  {{ $slot }}
</div>
