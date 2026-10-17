@extends('layouts.marketing')
@section('title', 'User manual')
@section('desc', 'How to use True-Doctor, chapter by chapter: patients, visits, billing, pharmacy, the lab, reports and the mobile app.')
@section('content')
<section>
  <div class="wrap page">
    <h1>True-Doctor user manual</h1>
    <p>Everything you do in True-Doctor, one chapter per area of work. The same manual is inside the system under <b>User manual</b>, in the mobile app, and in every copy you buy.</p>
    <p><a href="{{ route('manual.pdf') }}" target="_blank" rel="noopener">Download the whole manual as a PDF</a></p>
    <ol>
      @foreach($chapters as $c)<li><a href="{{ route('manual.show', $c['slug']) }}">{{ $c['title'] }}</a></li>@endforeach
    </ol>
  </div>
</section>
@endsection
