<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Subscription ended · True-Doctor</title>
  <link rel="icon" type="image/png" sizes="48x48" href="{{ asset('images/favicon.png') }}">
  <meta name="theme-color" content="#ffffff">
  <meta name="color-scheme" content="light">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@200;300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="{{ asset('vendor/fa/css/all.min.css') }}">
  @vite(['resources/css/admin.css'])
</head>
<body>
  <div class="blk-wrap">
    <div class="tb-card blk-card">
      <div class="tb-card-body blk-body">
        <div class="blk-icon"><i class="fas fa-calendar-xmark" aria-hidden="true"></i></div>
        <h1 class="blk-title">Subscription ended</h1>
        <p class="muted">
          {{ auth()->user()?->hospital?->name ?? 'Your hospital' }}'s subscription has ended, so the
          system is temporarily unavailable. Ask your hospital administrator to renew it — everything
          picks up right where it left off.
        </p>
        <form method="POST" action="{{ route('admin.logout') }}" class="blk-actions">
          @csrf
          <button type="submit" class="btn-tb btn-tb-ghost"><i class="fas fa-right-from-bracket" aria-hidden="true"></i> Sign out</button>
        </form>
      </div>
    </div>
  </div>
</body>
</html>
