@extends('layouts.marketing')
@section('title', $chapter['title'].' · User manual')
@section('desc', 'True-Doctor user manual: '.$chapter['title'].'.')
@section('content')
@php($i = array_search($chapter['slug'], array_column($chapters, 'slug'), true))
<section>
  <div class="wrap page">
    <p><a href="{{ route('manual.index') }}">User manual</a></p>
    <h1>{{ $chapter['title'] }}</h1>
    {!! $html !!}
    <p>
      @if($i > 0)<a href="{{ route('manual.show', $chapters[$i - 1]['slug']) }}">Previous: {{ $chapters[$i - 1]['title'] }}</a>@endif
      @if($i !== false && $i < count($chapters) - 1) · <a href="{{ route('manual.show', $chapters[$i + 1]['slug']) }}">Next: {{ $chapters[$i + 1]['title'] }}</a>@endif
    </p>
  </div>
</section>
@endsection
