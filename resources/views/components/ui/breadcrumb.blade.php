@props(['crumbs' => []])
{{-- $crumbs: ['Label' => route-url, ..., 'Current' => null] — every link is wire:navigate. --}}
<nav class="tb-breadcrumb" aria-label="Breadcrumb">
  @foreach($crumbs as $label => $url)
    @if(! $loop->first)<span aria-hidden="true">/</span>@endif
    @if($url)<a wire:navigate href="{{ $url }}">{{ $label }}</a>@else<span class="current" aria-current="page" style="margin:0;color:inherit;">{{ $label }}</span>@endif
  @endforeach
</nav>
