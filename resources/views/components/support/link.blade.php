@props(['context' => 'site.general', 'vars' => [], 'label' => null, 'icon' => true, 'contact' => null])
@php
  $contact ??= \App\Support\SupportContact::current();
  // Through /go/whatsapp on the public site, so the tap is counted (GoController).
  $href = \App\Http\Controllers\GoController::whatsappUrl($context, $vars, $contact);
@endphp
@if($href !== '')
  <a href="{{ $href }}" target="_blank" rel="noopener nofollow" {{ $attributes }}>
    @if($icon)<x-site.icon name="fa-whatsapp" /> @endif{{ $label ?? $slot }}
  </a>
@endif
