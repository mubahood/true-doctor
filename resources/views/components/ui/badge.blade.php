@props(['tone' => 'neutral'])
{{-- tone: active|success|info|warn|danger|neutral, or an enum's badge() class (badge-*) --}}
@php($cls = str_starts_with($tone, 'badge-') ? $tone : 'badge-'.$tone)
<span {{ $attributes->merge(['class' => "badge-tb $cls"]) }}>{{ $slot }}</span>
