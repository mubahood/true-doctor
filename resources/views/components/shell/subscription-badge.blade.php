@php
    /**
     * Where the hospital stands with its subscription, in the header where the
     * admin cannot miss it. Only shown to the person who can act on it — other
     * staff cannot subscribe or pay, so for them it would be noise.
     *
     * Plain Blade, not Livewire: it is read-only, and the topbar is outside the
     * @persist-ed shell, so it re-renders on every wire:navigate anyway.
     */
    $user = Auth::user();
    $badge = $user?->can('manage-settings')
        ? app(\App\Support\SubscriptionState::class)->badge(app(\App\Support\HospitalSettings::class)->hospital())
        : null;
@endphp

@if($badge)
  <a wire:navigate href="{{ route('admin.subscription.index') }}"
     @class(['sub-badge', 'tone-'.$badge->tone, 'is-urgent' => $badge->urgent])
     title="{{ $badge->nudge }}">
    <i class="fas {{ $badge->icon() }}" aria-hidden="true"></i>
    <span class="sub-badge-label">{{ $badge->label }}</span>
    <span class="sr-only">— {{ $badge->nudge }}</span>
  </a>
@endif
