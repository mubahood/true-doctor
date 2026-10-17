@extends('layouts.marketing')
@section('title', 'Installation guide — True-Doctor')
@section('desc', 'Install True-Doctor on your own server.')
@section('content')
<section><div class="wrap own-portal">
  <p><a href="{{ route('standalone.portal') }}"><x-site.icon name="fa-arrow-left" /> Back to your downloads</a></p>
  <div class="own-card own-guide"><div class="own-md">{!! $guide !!}</div></div>
</div></section>
@endsection
