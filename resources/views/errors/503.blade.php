{{-- Two kinds of 503. Maintenance — `down --render=errors::503`, which the
     updater and deploy run — passes $retryAfter, and the reload is Laravel's
     `Refresh` header (`--refresh`), which a plain abort(503) never sends and
     Livewire's error dialog never obeys. Anything else is a plain outage. --}}
@extends('errors.layout')
@php
  try {
      $maintenance = isset($retryAfter) || app()->isDownForMaintenance();
  } catch (\Throwable) {
      $maintenance = isset($retryAfter);
  }
@endphp
@section('code', '503')
@section('title', $maintenance ? 'Being updated — back in a few minutes' : 'Temporarily unavailable')
@section('message', $maintenance
    ? 'The system is briefly closed while it is updated or maintained. This page tries again by itself.'
    : 'Something this page needs is not available just now. Try again shortly; if it keeps happening, tell your administrator.')
@section('actions')
  <a class="err-btn" href="javascript:location.reload()">Try now</a>
@endsection
