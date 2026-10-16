@extends('layouts.marketing')
@section('title', $page['title'])
@section('desc', $page['description'])
@section('og', 'for-'.$slug)

@section('content')
@php($practice = $page['name'])

<section class="hero compact">
  <div class="wrap">
    <div class="eyebrow">For {{ $practice }}</div>
    <h1>{{ $page['h1'] }}</h1>
    <p class="lead">{{ $page['intro'] }}</p>
    <div class="ctas">
      @auth
        <a href="{{ route('admin.dashboard') }}" class="btn lg">Go to your dashboard <x-site.icon name="arrow-right" size="16" /></a>
      @else
        <a href="{{ route('register', ['practice' => $page['sector']]) }}" class="btn lg">Start free for 14 days <x-site.icon name="arrow-right" size="16" /></a>
        @if($demo)<a href="{{ route('demo') }}" class="btn plain lg">Open the live demo</a>@endif
      @endauth
    </div>
    <p class="hero-wa"><x-support.link context="site.page" :vars="['page' => $practice]" :icon="false">Questions? WhatsApp us</x-support.link></p>
  </div>
</section>

@foreach($page['sections'] as $i => $section)
  <section @class(['band-surface' => $i % 2 === 0])>
    <div class="wrap narrow">
      <h2>{{ $section['heading'] }}</h2>
      <p class="sec-body">{{ $section['body'] }}</p>
      @if(! empty($section['points']))
        <ul class="ticks">
          @foreach($section['points'] as $point)
            <li><x-site.icon name="check" size="16" /><span>{{ $point }}</span></li>
          @endforeach
        </ul>
      @endif
    </div>
  </section>
@endforeach

@if(! empty($page['coming']))
  <section>
    <div class="wrap narrow">
      <h2>On the way for {{ $practice }}</h2>
      <p class="sec-body">Being built now. We say so rather than pretend it is done.</p>
      <ul class="ticks soon">
        @foreach($page['coming'] as $item)
          <li><x-site.icon name="clock" size="16" /><span>{{ $item }}</span></li>
        @endforeach
      </ul>
    </div>
  </section>
@endif

@if(! empty($page['faq']))
  <section class="band-surface">
    <div class="wrap narrow">
      <div class="sec-head"><h2>Questions {{ $practice }} ask</h2></div>
      <x-site.faq :items="array_map(fn ($qa) => [e($qa[0]), e($qa[1])], $page['faq'])" />
    </div>
  </section>
@endif

<section>
  <div class="wrap narrow">
    <h2 class="related-h">Also built for</h2>
    <p class="related">
      @foreach($others as $otherSlug => $other)
        <a href="{{ route('for', $otherSlug) }}">{{ ucfirst($other['name']) }}</a>
      @endforeach
      <a href="{{ route('features') }}">Every module</a>
      <a href="{{ route('pricing') }}">Pricing</a>
    </p>
  </div>
</section>

@include('marketing.partials.close', ['page' => $practice])
@endsection

@push('structured-data')
  <x-site.breadcrumbs :trail="['Home' => route('home'), ucfirst($practice) => null]" />
@endpush
