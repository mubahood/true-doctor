@php $contact = $contact ?? \App\Support\SupportContact::current(); @endphp
{{-- WhatsApp, phone, email and hours: the one block every footer and email repeats. --}}
@if($contact->hasWhatsapp())
  <a href="{{ $contact->waLink($context ?? 'site.general') }}" target="_blank" rel="noopener"><i class="fab fa-whatsapp" aria-hidden="true"></i> WhatsApp {{ $contact->whatsappDisplay() }}</a>
@endif
@if($contact->phone() !== '')
  <a href="{{ $contact->phoneLink() }}"><i class="fas fa-phone" aria-hidden="true"></i> {{ $contact->phone() }}</a>
@endif
@if($contact->email() !== '')
  <a href="mailto:{{ $contact->email() }}">{{ $contact->email() }}</a>
@endif
@if($contact->hours() !== '')
  <span class="hours">{{ $contact->hours() }}</span>
@endif
@if($contact->statusUrl() !== '')
  <a href="{{ $contact->statusUrl() }}" rel="noopener"><i class="fas fa-signal" aria-hidden="true"></i> Service status</a>
@endif
