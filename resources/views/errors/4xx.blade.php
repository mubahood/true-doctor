@extends('errors.layout')
@section('code', $exception->getStatusCode())
@section('title', 'That request could not be completed')
@section('message', 'Go back and try again.')
