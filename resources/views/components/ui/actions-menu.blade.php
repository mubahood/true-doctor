@props(['label' => 'Actions', 'icon' => 'fa-ellipsis-vertical'])
{{--
  A row-level actions menu: one trigger, a popup of grouped actions.

  Usage:
    <x-ui.actions-menu label="Actions for {{ $visit->visit_no }}">
      <span class="tb-menu-sec">Clinical</span>
      <button type="button" role="menuitem" wire:click="vitals({{ $visit->id }})">
        <i class="fas fa-heart-pulse" aria-hidden="true"></i> Record vitals
      </button>
      <hr>
      <a role="menuitem" wire:navigate href="…"><i class="fas fa-eye"></i> Open</a>
    </x-ui.actions-menu>

  Children are plain <button role="menuitem"> / <a role="menuitem"> elements —
  the same vocabulary as the topbar account menu — so a Livewire action is just
  wire:click, and `.danger` tints a destructive one.

  The popup is position:FIXED, placed from the trigger's rect when it opens.
  It has to be: every table sits in .tb-table-wrap{overflow-x:auto}, which
  clips an absolutely-positioned child, and a menu that opens inside a
  horizontally scrolling box would be cut off at the row's edge. Fixed also
  keeps it above the sticky footer. It is NOT teleported — the markup stays
  inside the component root so wire: bindings are never severed (same reason
  x-ui.modal avoids x-teleport).
--}}
<div class="tb-menu" x-data="tdMenu" x-on:keydown.escape.stop.prevent="close(true)">
  <button type="button" class="btn-tb btn-tb-ghost btn-tb-icon" x-ref="trigger"
          x-on:click.stop="toggle()"
          x-on:keydown.down.prevent="open ? focusItem(0) : toggle()"
          :aria-expanded="open ? 'true' : 'false'"
          aria-haspopup="menu" aria-label="{{ $label }}" title="{{ $label }}">
    <i class="fas {{ $icon }}" aria-hidden="true"></i>
  </button>

  <div class="tb-menu-pop" role="menu" aria-label="{{ $label }}"
       x-ref="pop" x-show="open" x-cloak :style="style"
       x-on:click.outside="close(false)"
       x-on:click="close(false)"
       x-on:keydown.down.prevent="move(1)"
       x-on:keydown.up.prevent="move(-1)"
       x-on:keydown.home.prevent="focusItem(0)"
       x-on:keydown.end.prevent="focusItem(-1)"
       x-transition.opacity.duration.120ms>
    {{ $slot }}
  </div>
</div>
