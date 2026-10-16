@extends('layouts.marketing')
@section('title', $page['title'])
@section('desc', $page['description'])
@section('og', 'walkthrough')

@section('content')
<section class="hero compact">
  <div class="wrap">
    <div class="eyebrow">Free walkthrough</div>
    <h1>{{ $page['h1'] }}</h1>
    <p class="lead">{{ $page['intro'] }}</p>
    <div class="ctas">
      <x-support.link context="site.demo" class="btn lg">Book on WhatsApp</x-support.link>
      @if($support->phone() !== '')<a href="{{ $support->phoneLink() }}" class="btn plain lg"><x-site.icon name="phone" size="16" /> Call {{ $support->phone() }}</a>@endif
    </div>
    @if($support->hours() !== '')<p class="hero-wa">{{ $support->hours() }}</p>@endif
  </div>
</section>

<section class="band-surface">
  <div class="wrap">
    <div class="steps">
      @foreach($page['steps'] as $i => [$title, $body])
        <div class="step">
          <div class="n" aria-hidden="true">{{ $i + 1 }}</div>
          <h2 class="step-h">{{ $title }}</h2>
          <p>{{ $body }}</p>
        </div>
      @endforeach
    </div>
  </div>
</section>

@if($demo)
<section>
  <div class="wrap narrow">
    <h2>Rather look around on your own first?</h2>
    <p class="sec-body">The demo hospital is open to anyone: pick a role and you are in.</p>
    <p><a href="{{ route('demo') }}" class="btn ghost">Open the demo <x-site.icon name="arrow-right" size="16" /></a></p>
  </div>
</section>
@endif
@endsection

@push('structured-data')
  <x-site.breadcrumbs :trail="['Home' => route('home'), 'Walkthrough' => null]" />
@endpush
