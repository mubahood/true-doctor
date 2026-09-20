{{-- Lazy, polled host for one named dashboard widget (plan §4.4 "Board"). --}}
<div wire:poll.60s.visible>
  @include($partial, $data)
</div>
