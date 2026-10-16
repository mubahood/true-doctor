@php
    /**
     * Help, one tap from every screen: WhatsApp (with the screen's name in the
     * message), a call and an email. Who answers comes from SupportContact: the
     * partner who installed a standalone copy, else the platform.
     */
    $support = \App\Support\SupportContact::current();
    $user = Auth::user();
    $hospital = app(\App\Support\HospitalSettings::class)->hospital();
    // The screen is filled in by the browser, which knows the current page title.
    $marker = '@@SCREEN@@';
    $wa = $support->waLink('admin.help', [
        'role' => $user?->role ? \Illuminate\Support\Str::headline($user->role) : 'staff',
        'hospital' => $hospital?->name ?? 'True-Doctor',
        'screen' => $marker,
    ]);
@endphp

@if($wa !== '' || $support->phone() !== '' || $support->email() !== '')
  <div class="tb-user-menu" :class="{'open': helpMenu}" x-data="{helpMenu:false}" @click.outside="helpMenu=false" @keydown.escape="helpMenu=false">
    <button type="button" class="tb-topbar-bell" @click="helpMenu=!helpMenu" :aria-expanded="helpMenu" aria-haspopup="menu" aria-label="Help and support" title="Help and support">
      <i class="fas fa-circle-question" aria-hidden="true"></i>
    </button>
    <div class="tb-user-dropdown" role="menu">
      @if($wa !== '')
        <a role="menuitem" target="_blank" rel="noopener" href="{{ $wa }}"
           x-bind:href="@js($wa).replace(@js(rawurlencode($marker)), encodeURIComponent(document.title.split(' · ')[0] || 'the system'))">
          <i class="fab fa-whatsapp" aria-hidden="true"></i> WhatsApp support
        </a>
      @endif
      @if($support->phone() !== '')
        <a role="menuitem" href="{{ $support->phoneLink() }}"><i class="fas fa-phone" aria-hidden="true"></i> Call {{ $support->phone() }}</a>
      @endif
      @if($support->email() !== '')
        <a role="menuitem" href="mailto:{{ $support->email() }}"><i class="fas fa-envelope" aria-hidden="true"></i> Email support</a>
      @endif
      @if($support->hours() !== '')
        <hr>
        <span class="tb-menu-note">{{ $support->hours() }}</span>
      @endif
    </div>
  </div>
@endif
