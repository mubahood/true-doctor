@props(['href', 'navigate' => true, 'hover' => false])
{{-- Every in-app link is a wire:navigate link (house rule 1). Pass :navigate="false"
     only for downloads, PDFs, external URLs and sign-out. --}}
<a href="{{ $href }}" @if($navigate) wire:navigate{{ $hover ? '.hover' : '' }} @endif {{ $attributes }}>{{ $slot }}</a>
