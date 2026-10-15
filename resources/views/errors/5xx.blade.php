@extends('errors.layout')
@section('code', $exception->getStatusCode())
@section('title', 'Something went wrong on our side')
@section('message', 'It has been written to the server log. Try again in a moment.')
