@extends('layouts.marketing')
@section('title', $page['title'])
@section('desc', $page['description'])
@section('og', 'about')

@section('content')
<section class="hero compact">
  <div class="wrap">
    <div class="eyebrow">About us</div>
    <h1>{{ $page['h1'] }}</h1>
    <p class="lead">{{ $page['intro'] }}</p>
  </div>
</section>

@foreach($page['sections'] as $i => [$heading, $body])
  <section @class(['band-surface' => $i % 2 === 0])>
    <div class="wrap narrow">
      <h2>{{ $heading }}</h2>
      <p class="sec-body">{{ $body }}</p>
    </div>
  </section>
@endforeach

<section>
  <div class="wrap narrow">
    <h2>The company</h2>
    <dl class="facts">
      @foreach(array_filter([
        'Legal name' => $company['legal_name'] ?? null,
        'Registration number' => $company['registration_no'] ?? null,
        'TIN' => $company['tin'] ?? null,
        'Address' => $company['address'] ?? null,
        'P.O. Box' => $company['po_box'] ?? null,
        'Country' => $company['country'] ?? null,
        'Data protection registration' => $company['pdpo_registration'] ?? null,
      ]) as $label => $value)
        <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
      @endforeach
    </dl>
    <div class="facts-contact"><x-support.facts context="site.page" /></div>
  </div>
</section>
@endsection

@push('structured-data')
  <x-site.breadcrumbs :trail="['Home' => route('home'), 'About' => null]" />
@endpush
