@extends('layouts.marketing')
@section('title', $page['title'])
@section('desc', $page['description'])
@section('og', 'demo')

@section('content')
<section class="hero compact">
  <div class="wrap">
    <div class="eyebrow">The demo hospital</div>
    <h1>{{ $page['h1'] }}</h1>
    <p class="lead">{{ $page['intro'] }}</p>
  </div>
</section>

<section class="band-surface">
  <div class="wrap">
    @if($roles === [])
      <p class="sec-body center">The demo is not open right now. <x-support.link context="site.demo" :icon="false">Ask us for a walkthrough</x-support.link> instead.</p>
    @else
      <div class="grid roles">
        @foreach($roles as $role)
          <form method="POST" action="{{ route('demo.enter', $role['key']) }}" class="card role-card">
            @csrf
            <h2 class="card-h">{{ $role['label'] }}</h2>
            <p>{{ $role['headline'] }}</p>
            <ul>@foreach($role['can'] as $can)<li>{{ $can }}</li>@endforeach</ul>
            <button type="submit" class="btn sm">Walk in as {{ $role['label'] }} <x-site.icon name="arrow-right" size="14" /></button>
            <span class="role-min">About {{ $role['minutes'] }} minutes</span>
          </form>
        @endforeach
      </div>
      <p class="sec-body center demo-note">Sample data only. @if($resets)It is put back every night, so @endif change anything you like.
        <x-support.link context="site.demo" :icon="false">Prefer a guided walkthrough?</x-support.link></p>
    @endif
  </div>
</section>
@endsection

@push('structured-data')
  <x-site.breadcrumbs :trail="['Home' => route('home'), 'Demo' => null]" />
@endpush
