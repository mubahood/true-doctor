@props([
    'label' => 'What this page is',
    'placement' => 'below',      // below | above
])
{{--
  A note that is there when you want it and gone when you do not.

  Pages were opening with a paragraph explaining themselves, which the person
  who reads it once then has to scroll past every day afterwards. The
  explanation is worth keeping — it says what a page is FOR, which nothing else
  on the screen does — so it moves behind an (i) beside the title.

  Hover shows it, because that is what a reader tries first. Click also shows
  it and PINS it, because hover does not exist on a phone and a note that
  vanishes when the pointer moves cannot be read at length. Escape and a click
  outside close it, and the trigger keeps its own focus ring so the keyboard
  reaches it like any other button.

      <x-ui.explain label="What the ledger is">
        Everything that came in and everything that went out…
      </x-ui.explain>
--}}
<span class="tb-explain" x-data="{ open: false, pinned: false }"
      x-on:keydown.escape.stop="open = false; pinned = false">
  <button type="button" class="tb-explain-btn"
          x-on:mouseenter="open = true"
          x-on:mouseleave="open = pinned"
          x-on:click.stop="pinned = ! pinned; open = pinned"
          x-on:click.outside="pinned = false; open = false"
          :aria-expanded="open ? 'true' : 'false'"
          aria-label="{{ $label }}" title="{{ $label }}">
    <i class="fas fa-circle-info" aria-hidden="true"></i>
  </button>

  <span @class(['tb-explain-pop', 'is-above' => $placement === 'above'])
        role="note" x-show="open" x-cloak x-transition.opacity.duration.120ms>
    {{ $slot }}
  </span>
</span>
