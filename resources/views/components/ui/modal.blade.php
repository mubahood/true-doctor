@props(['show', 'title' => '', 'size' => 'lg', 'width' => null, 'actions' => null, 'autosaves' => false])
{{--
  The standard dialog for every create/edit/action form in the back office:
  a centred modal over a dimmed backdrop, generously sized, with a scrolling
  body and a sticky header and footer. On phones it becomes a full-screen sheet.

  Usage: <x-ui.modal show="showForm" title="New department">…form…</x-ui.modal>
         <x-ui.modal show="showForm" title="Register patient" size="xl">…</x-ui.modal>

  An optional `actions` slot puts a control in the header, beside the close
  button, for the one thing a reader reaches for most:
      <x-ui.modal show="show" title="…">
        <x-slot:actions><button …>Add order</button></x-slot:actions>
        …
      </x-ui.modal>

  `show` is the name of a boolean Livewire property; the dialog entangles to it
  so opening and closing are driven by the server component and animated by
  Alpine. No x-teleport — the content stays inside the component root so wire:
  bindings are never severed; position:fixed handles the overlay.

  Sizes: sm 460 · md 640 · lg 860 (default) · xl 1100 · full 96vw.
  `width` still works for call sites that pass an explicit CSS width.

  Behaviour (see resources/js/admin.js → tdModal):
   - focus is trapped while open (x-trap) and restored to the trigger on close;
   - Escape / backdrop / Cancel go through requestClose(): if the form has been
     edited (data-td-dirty) the user is asked to confirm discarding changes.
     Pass `autosaves` on a dialog that saves as it is edited — there is
     nothing to discard, so asking is a lie;
   - body scroll is locked while open (reference-counted).
--}}
@php
    $max = $width ?? match ($size) {
        'sm' => '460px',
        'md' => '640px',
        'xl' => '1100px',
        'full' => '96vw',
        default => '860px',
    };
@endphp
<div x-data="tdModal($wire.entangle('{{ $show }}'))"
     x-show="open" x-cloak
     class="tb-modal-backdrop"
     x-transition.opacity.duration.150ms
     @keydown.escape.window="open && isTop && requestClose()"
     @click.self="requestClose()"
     @if(! $autosaves) @input="$root.dataset.tdDirty = '1'" @endif
     x-effect="if (!open) delete $root.dataset.tdDirty"
     style="display:none;">
  <div class="tb-modal" style="max-width:min({{ $max }}, 96vw);"
       role="dialog" aria-modal="true" aria-labelledby="modal-title-{{ $show }}"
       x-show="open" x-trap.inert="open"
       x-transition:enter="tb-modal-enter" x-transition:enter-start="tb-modal-start" x-transition:enter-end="tb-modal-end">
    <div class="tb-modal-head">
      <h2 id="modal-title-{{ $show }}">{{ $title }}</h2>
      {{-- The one action a reader reaches for most, kept where it is always
           visible however far the body has scrolled. --}}
      @if($actions)
        <div class="tb-modal-head-acts">{{ $actions }}</div>
      @endif
      <button type="button" class="tb-modal-x" @click="requestClose()" aria-label="Close"><i class="fas fa-xmark" aria-hidden="true"></i></button>
    </div>
    {{ $slot }}
  </div>
</div>
