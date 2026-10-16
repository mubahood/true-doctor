@props(['context' => 'site.general', 'vars' => []])
@php
  $contact = \App\Support\SupportContact::current();
  $href = $contact->waLink($context, $vars);
@endphp
@if($href !== '')
  <a href="{{ $href }}" target="_blank" rel="noopener" class="wa-float" aria-label="Chat with True-Doctor on WhatsApp">
    <i class="fab fa-whatsapp" aria-hidden="true"></i>
    <span>WhatsApp us</span>
  </a>
@endif
