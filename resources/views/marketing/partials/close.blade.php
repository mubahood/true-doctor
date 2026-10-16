{{-- The closing band of an inner page: try it, or talk to us about this page. --}}
<section class="band">
  <div class="wrap">
    <h2>Try it with your own work in mind</h2>
    <p>Fourteen days free, every module on, no card. Or ask us first on WhatsApp.</p>
    <div class="ctas">
      @auth
        <a href="{{ route('admin.dashboard') }}" class="btn lg">Go to your dashboard</a>
      @else
        <a href="{{ route('register') }}" class="btn lg">Start free for 14 days</a>
      @endauth
      <x-support.link context="site.page" :vars="['page' => $page ?? 'True-Doctor']" class="btn ghost lg">WhatsApp us</x-support.link>
    </div>
  </div>
</section>
