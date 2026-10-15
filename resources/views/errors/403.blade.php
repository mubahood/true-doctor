{{-- A specific reason passed to abort(403, '…') — a lapsed subscription, an
     account from another organisation — is shown as it is. The framework's
     own generic denials get the plain explanation instead. --}}
@extends('errors.layout')
@php
  $reason = isset($exception) ? trim((string) $exception->getMessage()) : '';
  $generic = in_array($reason, ['', 'Forbidden', 'This action is unauthorized.', 'User does not have the right permissions.', 'User does not have the right roles.'], true);
@endphp
@section('code', '403')
@section('title', $generic ? 'This page is not open to your account' : 'This can’t be done right now')
@section('message', $generic ? 'Your role does not include it. If you need it for your work, ask an administrator to change your access.' : $reason)
