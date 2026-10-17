@props(['context' => 'site.general', 'vars' => []])
@php
  $contact = \App\Support\SupportContact::current();
  $href = \App\Http\Controllers\GoController::whatsappUrl($context, $vars, $contact);
@endphp
@if($href !== '')
  <a href="{{ $href }}" target="_blank" rel="noopener nofollow" class="wa-float" aria-label="Chat with True-Doctor on WhatsApp">
    <x-site.icon name="fa-whatsapp" />
    <span>WhatsApp us</span>
  </a>
@endif
