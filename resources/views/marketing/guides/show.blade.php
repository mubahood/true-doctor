@extends('layouts.marketing')
@section('title', $guide['title'])
@section('desc', $guide['description'])

@section('content')
@php([$productLabel, $productRoute, $productParams] = $guide['product'])
<section>
  <div class="wrap page">
    <h1>{{ $guide['h1'] }}</h1>
    <div class="updated">
      By {{ $author['name'] }}, {{ $author['credentials'] }}.
      Updated {{ \Illuminate\Support\Carbon::parse($guide['updated'])->format('j F Y') }}. {{ $guide['minutes'] }} minute read.
    </div>

    <p>{{ $guide['intro'] }}</p>

    @if(! empty($guide['shot']) && \App\Support\SiteShots::get($guide['shot']))
      <figure class="frame page-shot"><x-site.shot :name="$guide['shot']" sizes="(max-width: 820px) 100vw, 760px" /></figure>
    @endif

    @foreach($guide['sections'] as $section)
      <h2>{{ $section['heading'] }}</h2>
      @foreach($section['body'] as $paragraph)
        <p>{{ $paragraph }}</p>
      @endforeach
      @if(! empty($section['points']))
        <ul>
          @foreach($section['points'] as $point)<li>{{ $point }}</li>@endforeach
        </ul>
      @endif
    @endforeach

    <h2>Sources</h2>
    <ul>
      @foreach($guide['sources'] as [$label, $href])
        <li><a class="link" href="{{ $href }}" target="_blank" rel="noopener nofollow">{{ $label }}</a></li>
      @endforeach
    </ul>

    <div class="callout">
      <p><a class="link" href="{{ route($productRoute, $productParams) }}">{{ $productLabel }}</a></p>
    </div>

    <h2>More guides</h2>
    <ul>
      @foreach($others as $otherSlug => $other)
        <li><a class="link" href="{{ route('guides.show', $otherSlug) }}">{{ $other['h1'] }}</a></li>
      @endforeach
    </ul>
  </div>
</section>
@endsection

@push('structured-data')
  <x-site.breadcrumbs :trail="['Home' => route('home'), 'Guides' => route('guides.index'), $guide['h1'] => null]" />
  <script type="application/ld+json">
    {!! json_encode([
      '@context' => 'https://schema.org',
      '@type' => 'Article',
      'headline' => $guide['h1'],
      'description' => $guide['description'],
      'dateModified' => $guide['updated'],
      'author' => ['@type' => 'Person', 'name' => $author['name']],
      'publisher' => ['@type' => 'Organization', 'name' => 'True-Doctor'],
      'mainEntityOfPage' => route('guides.show', $slug),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
  </script>
@endpush
