@props(['context' => 'site.general', 'vars' => [], 'label' => null, 'icon' => true, 'contact' => null])
@php
  $contact ??= \App\Support\SupportContact::current();
  $href = $contact->waLink($context, $vars);
@endphp
@if($href !== '')
  <a href="{{ $href }}" target="_blank" rel="noopener" {{ $attributes }}>
    @if($icon)<i class="fab fa-whatsapp" aria-hidden="true"></i> @endif{{ $label ?? $slot }}
  </a>
@endif
