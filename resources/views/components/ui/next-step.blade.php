@props(['gate', 'action' => null])
{{--
  The next step, at the foot of the section that earns it. A shut gate says
  what is shutting it, in figures — a greyed-out button teaches nobody
  anything (docs/visits.md).
--}}
@if($gate)
  <div class="tb-next">
    @if($gate['automatic'])
      <span class="tb-next-auto">
        <i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i>
        Completes itself once the bill is paid in full.
      </span>
      @if($gate['blocker'])<span class="tb-next-block">{{ $gate['blocker'] }}</span>@endif
    @elseif($gate['ready'])
      <span class="tb-next-say">Everything here is done.</span>
      <button type="button" class="btn-tb btn-tb-sm btn-tb-primary"
              wire:click="advanceVisit" wire:loading.attr="disabled" wire:target="advanceVisit">
        <span wire:loading.remove wire:target="advanceVisit">
          {{ $gate['label'] }} <i class="fas fa-arrow-right" aria-hidden="true"></i>
        </span>
        <span wire:loading wire:target="advanceVisit">Moving…</span>
      </button>
    @elseif($gate['blocker'])
      <span class="tb-next-block">
        <i class="fas fa-lock" aria-hidden="true"></i>
        {{ $gate['label'] }} — {{ $gate['blocker'] }}
      </span>
      {{-- The thing that opens this gate, beside the gate it opens. Putting it
           up in the section header made it a button people met before they
           knew what it was for. --}}
      {{ $action }}
    @endif
  </div>
@endif
