@php
  /**
   * The back-office menu. Its definition — sections, gates, order, and the
   * reasons for them — is App\Support\Navigation; this partial only draws it.
   *
   * @var \App\Models\User $u
   */
  $u = Auth::user();

  // Setup mode: the admin is still being held in the wizard, so every page
  // outside the allow-list below bounces straight back to it. The layout passes
  // this in (it also keys the persisted sidebar on it); the fallback keeps the
  // partial usable on its own.
  $setup = app(\App\Support\OnboardingStatus::class);
  $inSetup = $inSetup ?? $setup->mustCompleteSetup($u);
  $setupProgress = $inSetup ? $setup->progress($u->hospital()->first()) : null;

  // One definition for the web sidebar and the app's menu (GET /api/v1/meta):
  // what is shown, to whom, in what order — see App\Support\Navigation.
  $menu = \App\Support\Navigation::for($u, $inSetup, fn (array $match) => request()->routeIs(...$match));
  $rendered = $menu['sections'];
  $activeGroup = $menu['activeGroup'];
@endphp

@if($inSetup)
  {{-- Why the menu is short, and how far off the end of it is. --}}
  <div class="tb-nav-setup">
    <x-ui.progress :value="$setupProgress['done']" :max="$setupProgress['total']" label="Setup" />
    <p>The rest of the menu unlocks when your hospital is set up.</p>
  </div>
@endif

{{-- Each section is its own labelled list, so the hierarchy a sighted user gets
     from the headings is the same one a screen reader announces. --}}
<div class="tb-nav-list" x-data="tdNav('{{ $activeGroup }}')">
  @foreach($rendered as $s)
    <div class="tb-nav-sec">
      @if($s['label'])
        <h2 class="tb-nav-section" id="navsec-{{ $s['key'] }}">{{ $s['label'] }}</h2>
      @endif
      <ul @if($s['label']) aria-labelledby="navsec-{{ $s['key'] }}" @endif>
        @foreach($s['entries'] as $e)
          @if($e['type'] === 'link')
            {{-- A destination in its own right: same rank as a group header, no caret. --}}
            <li class="tb-nav-item {{ $e['active'] ? 'active' : '' }}" data-nav-item>
              <a wire:navigate.hover href="{{ route($e['route']) }}" @if($e['active']) aria-current="page" @endif>
                <i class="fas {{ $e['icon'] }}" aria-hidden="true"></i><span class="glabel">{{ $e['label'] }}</span>
              </a>
            </li>
          @else
            <li class="tb-nav-group">
              <button type="button" class="tb-nav-gh {{ $e['active'] ? 'has-active' : '' }}" data-nav-group="{{ $e['key'] }}"
                      :class="{ 'open': open === '{{ $e['key'] }}' }"
                      @click="toggle('{{ $e['key'] }}')"
                      :aria-expanded="open === '{{ $e['key'] }}'"
                      aria-controls="navsub-{{ $e['key'] }}">
                <i class="fas {{ $e['icon'] }} gicon" aria-hidden="true"></i>
                <span class="glabel">{{ $e['label'] }}</span>
                <i class="fas fa-chevron-down gcaret" aria-hidden="true"></i>
              </button>
              <ul class="tb-nav-sub" id="navsub-{{ $e['key'] }}" x-show="open === '{{ $e['key'] }}'" x-collapse x-cloak>
                @foreach($e['items'] as $it)
                  <li class="tb-nav-item {{ $it['active'] ? 'active' : '' }}" data-nav-item data-nav-group="{{ $e['key'] }}">
                    <a wire:navigate.hover href="{{ route($it['route']) }}" @if($it['active']) aria-current="page" @endif><i class="fas {{ $it['icon'] }}" aria-hidden="true"></i> {{ $it['label'] }}</a>
                  </li>
                @endforeach
              </ul>
            </li>
          @endif
        @endforeach
      </ul>
    </div>
  @endforeach
</div>
