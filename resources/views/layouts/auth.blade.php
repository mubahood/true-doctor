<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', 'Staff sign in') — True-Doctor</title>
  <meta name="description" content="Staff sign-in for True-Doctor hospital management.">
  <link rel="icon" type="image/png" sizes="48x48" href="{{ asset('images/favicon.png') }}">
  <link rel="apple-touch-icon" href="{{ asset('images/logo-192.png') }}">
  <meta name="theme-color" content="#ffffff">
  <meta name="color-scheme" content="light">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@200;300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="{{ asset('vendor/fa/css/all.min.css') }}">
  @vite(['resources/css/auth.css', 'resources/js/auth.js'])
  @stack('styles')
</head>
<body>
  @include('partials.doodle-bg')

  {{-- One column by default. A page that yields an `aside` gets the context
       rail beside the form; `wide` widens the form column for long forms. --}}
  <div @class(['auth-shell', trim($__env->yieldContent('shell')) => trim($__env->yieldContent('shell')) !== '', 'is-split' => $__env->hasSection('aside')])>
    <main class="auth-main">
      <div class="a-brand">
        <img src="{{ asset('images/logo-icon.png') }}" class="mk" alt="">
        <b>True-Doctor</b>
        @if(app()->environment('local'))<span class="env">Local</span>@endif
      </div>

      <div class="card">
        @yield('form')
      </div>
    </main>

    @hasSection('aside')
      <aside class="auth-aside">
        @yield('aside')
      </aside>
    @endif
  </div>

  @stack('scripts')
</body>
</html>
