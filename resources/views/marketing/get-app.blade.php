@extends('layouts.marketing')
@section('title', $page['title'])
@section('desc', $page['description'])
@section('og', 'get-app')

@section('content')
<section class="hero compact">
  <div class="wrap">
    <div class="eyebrow">The Android app</div>
    <h1>{{ $page['h1'] }}</h1>
    <p class="lead">{{ $page['intro'] }}</p>
    <div class="ctas">
      @if($appUrl)
        <a href="{{ $appUrl }}" class="btn lg" rel="noopener"><x-site.icon name="download" size="16" /> Get the app</a>
      @else
        <x-support.link context="site.page" :vars="['page' => 'the Android app']" class="btn lg">Ask us for the app</x-support.link>
      @endif
    </div>
    @if($appUrl && $qr)
      <div class="app-qr" aria-label="Scan to download the app">{!! $qr !!}<span>Scan with your phone</span></div>
    @endif
  </div>
</section>

<section class="band-surface">
  <div class="wrap">
    <div class="grid two">
      @foreach($page['points'] as [$title, $body])
        <div class="card">
          <h2 class="card-h">{{ $title }}</h2>
          <p>{{ $body }}</p>
        </div>
      @endforeach
    </div>
  </div>
</section>

@include('marketing.partials.close', ['page' => 'the Android app'])
@endsection

@push('structured-data')
  <x-site.breadcrumbs :trail="['Home' => route('home'), 'The app' => null]" />
@endpush
