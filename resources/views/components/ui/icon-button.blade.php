@props(['label', 'icon', 'href' => null, 'navigate' => true, 'variant' => 'ghost'])
{{-- Icon-only control with a mandatory accessible name (house rule 17). --}}
@if($href)
  <a href="{{ $href }}" @if($navigate) wire:navigate @endif {{ $attributes->merge(['class' => "btn-tb btn-tb-$variant btn-tb-icon"]) }} aria-label="{{ $label }}" title="{{ $label }}"><i class="fas {{ $icon }}" aria-hidden="true"></i></a>
@else
  <button type="button" {{ $attributes->merge(['class' => "btn-tb btn-tb-$variant btn-tb-icon"]) }} aria-label="{{ $label }}" title="{{ $label }}"><i class="fas {{ $icon }}" aria-hidden="true"></i></button>
@endif
