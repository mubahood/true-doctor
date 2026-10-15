@extends('errors.layout')
@section('code', '401')
@section('title', 'Please sign in')
@section('message', 'You need to be signed in to open this page.')
@section('actions')
  <a class="err-btn" href="{{ route('admin.login') }}">Sign in</a>
@endsection
