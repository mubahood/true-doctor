@props([
    'name',                       // the wire:model property
    'has' => false,               // is there already something to show
    'accept' => 'image/*',
    'hint' => 'PNG with a transparent background looks best. Up to 2 MB, fitted without cropping.',
    'label' => 'Logo',
    'idle' => 'Drop an image here, or click to choose one',
    'multiple' => false,          // a result is often several files
])
{{--
  A file field you can drop onto.

  `<input type="file">` is a button that says "Choose File" and nothing about
  what it wants. Dragging a logo onto the page is what people try first, and it
  did nothing. This is the same input — still keyboard reachable, still the
  thing the browser validates — with the whole area as a drop target and the
  preview drawn inside it, so what you dropped and where it landed are the same
  place.

  Alpine only decorates. `dragover` sets a class, `drop` hands the files to the
  input and fires `change`, which is what Livewire's file upload already
  listens for — nothing here uploads anything itself.
--}}
<div class="tb-form-group">
  <label class="tb-label" for="drop-{{ $name }}">{{ $label }}</label>

  <div x-data="{ over: false }"
       @class(['tb-drop', 'has-file' => $has])
       x-on:dragover.prevent="over = true"
       x-on:dragenter.prevent="over = true"
       x-on:dragleave.prevent="over = false"
       x-on:drop.prevent="
          over = false;
          if ($event.dataTransfer.files.length) {
              $refs.input.files = $event.dataTransfer.files;
              $refs.input.dispatchEvent(new Event('change', { bubbles: true }));
          }
       "
       :class="over && 'is-over'">

    {{-- The input covers the whole box: clicking anywhere opens the picker,
         and it keeps its own focus ring for the keyboard. --}}
    <input id="drop-{{ $name }}" type="file" x-ref="input"
           wire:model="{{ $name }}" accept="{{ $accept }}" @if($multiple) multiple @endif
           class="tb-drop-input" aria-describedby="drop-hint-{{ $name }}">

    <div class="tb-drop-face">
      @if($has)
        <div class="tb-drop-shot">{{ $preview ?? '' }}</div>
      @else
        <i class="fas fa-cloud-arrow-up tb-drop-icon" aria-hidden="true"></i>
      @endif

      <div class="tb-drop-words">
        <span class="tb-drop-lead">{{ $has ? 'Drop a new one, or click to replace' : $idle }}</span>
        <span class="tb-drop-hint" id="drop-hint-{{ $name }}">{{ $hint }}</span>
      </div>

      <div class="tb-drop-acts">{{ $actions ?? '' }}</div>
    </div>

    <div class="tb-drop-busy" wire:loading wire:target="{{ $name }}">
      <i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Uploading…
    </div>
  </div>

  @error($name)<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
</div>
