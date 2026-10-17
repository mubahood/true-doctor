@props(['name', 'alt' => null, 'eager' => false, 'sizes' => '(max-width: 820px) 100vw, 640px'])
{{-- A real screenshot (App\Support\SiteShots): true width and height, the
     800-wide copy for a phone, lazy unless it is the first thing on screen. --}}
@php($shot = \App\Support\SiteShots::get($name))
@php($alt ??= (string) config("landing.shots.{$name}", ''))
@if($shot)
  <img src="{{ \App\Support\SiteShots::url($shot['file']) }}"
       @isset($shot['small']) srcset="{{ \App\Support\SiteShots::url($shot['small']['file']) }} {{ $shot['small']['w'] }}w, {{ \App\Support\SiteShots::url($shot['file']) }} {{ $shot['w'] }}w" sizes="{{ $sizes }}" @endisset
       width="{{ $shot['w'] }}" height="{{ $shot['h'] }}" alt="{{ $alt }}"
       @if($eager) fetchpriority="high" @else loading="lazy" @endif decoding="async"
       {{ $attributes->merge(['class' => 'shot-img']) }}>
@endif
