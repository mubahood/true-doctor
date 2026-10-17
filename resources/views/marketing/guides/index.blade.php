@extends('layouts.marketing')
@section('title', $page['title'])
@section('desc', $page['description'])

@section('content')
<section>
  <div class="wrap page">
    <h1>{{ $page['h1'] }}</h1>
    <div class="updated">Updated {{ \Illuminate\Support\Carbon::parse($page['updated'])->format('j F Y') }}</div>
    <p>{{ $page['intro'] }}</p>

    @foreach($guides as $slug => $guide)
      <h2><a class="link" href="{{ route('guides.show', $slug) }}">{{ $guide['h1'] }}</a></h2>
      <p>{{ $guide['description'] }} {{ $guide['minutes'] }} minute read.</p>
    @endforeach

    <div class="callout">
      <p>A question these do not answer? <x-support.link context="site.page" :vars="['page' => 'Guides']" :icon="false" class="link">Ask us on WhatsApp</x-support.link>.</p>
    </div>
  </div>
</section>
@endsection

@push('structured-data')
  <x-site.breadcrumbs :trail="['Home' => route('home'), 'Guides' => null]" />
@endpush
