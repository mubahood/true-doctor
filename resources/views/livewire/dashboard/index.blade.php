<div>
  <h1 class="sr-only">Dashboard</h1>

  {{-- A visitor exploring the demonstration is told so on the page they land
       on, not only by a button in the corner they may never look at. Renders
       nothing for a real hospital. --}}
  @if(\App\Support\Demo::isDemoUser(auth()->user()))
    <div class="tb-demo-banner">
      <span class="tb-demo-dot" aria-hidden="true"></span>
      <div class="tb-demo-banner-text">
        <b>This is the demonstration hospital.</b>
        <span>Sample data, shared with everyone else trying the system, and wiped regularly. Change anything you like.</span>
      </div>
      <button type="button" class="btn-tb btn-tb-primary btn-tb-sm tb-demo-banner-cta"
              onclick="window.Livewire.dispatch('open-demo-invite')" aria-haspopup="dialog">
        Create your own hospital <i class="fas fa-arrow-right" aria-hidden="true"></i>
      </button>
    </div>
  @endif

  <div class="dash-root">
    @include('livewire.dashboard.roles.'.$role)
  </div>
</div>
