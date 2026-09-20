@props(['heading', 'sub' => null])
{{--
  The top of a quick view: what this record IS, in one line, and what it is
  about, in the line under it — then whatever badges say its state.

  Held once because twenty dialogs with twenty slightly different headers is
  twenty dialogs a reader has to learn separately.

  Usage:
      <x-ui.peek-head :heading="$bed->name" sub="Ward A · Room 3">
        <x-ui.badge tone="warn">Occupied</x-ui.badge>
      </x-ui.peek-head>
--}}
<div class="tb-peek-head">
  <div>
    <div class="tb-peek-when">{{ $heading }}</div>
    @if($sub !== null && $sub !== '')
      <div class="tb-peek-time">{{ $sub }}</div>
    @endif
  </div>
  @if(! $slot->isEmpty())
    <div class="tb-peek-badges">{{ $slot }}</div>
  @endif
</div>
