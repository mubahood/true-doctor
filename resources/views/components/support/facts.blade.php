@php $contact = $contact ?? \App\Support\SupportContact::current(); @endphp
{{-- WhatsApp, phone, email and hours: the one block every footer and email repeats. --}}
@if($contact->hasWhatsapp())
  <a href="{{ \App\Http\Controllers\GoController::whatsappUrl($context ?? 'site.general', [], $contact) }}" target="_blank" rel="noopener nofollow"><x-site.icon name="fa-whatsapp" /> WhatsApp {{ $contact->whatsappDisplay() }}</a>
@endif
@if($contact->phone() !== '')
  <a href="{{ $contact->phoneLink() }}"><x-site.icon name="fa-phone" /> {{ $contact->phone() }}</a>
@endif
@if($contact->email() !== '')
  <a href="mailto:{{ $contact->email() }}">{{ $contact->email() }}</a>
@endif
@if($contact->hours() !== '')
  <span class="hours">{{ $contact->hours() }}</span>
@endif
@if($contact->statusUrl() !== '')
  <a href="{{ $contact->statusUrl() }}" rel="noopener"><x-site.icon name="fa-signal" /> Service status</a>
@endif
