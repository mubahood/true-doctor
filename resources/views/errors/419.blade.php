@extends('errors.layout')
@section('code', '419')
@section('title', 'This page sat open for too long')
@section('message', 'For your security the form expired. Go back, refresh the page and try again — nothing was saved twice.')
@section('actions')
  <a class="err-btn" href="javascript:history.back()">Go back</a>
  <a class="err-btn ghost" href="{{ route('admin.login') }}">Sign in again</a>
@endsection
