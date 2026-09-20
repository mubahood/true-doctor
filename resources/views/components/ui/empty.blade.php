@props(['icon' => 'fa-inbox', 'noun' => 'records', 'filtered' => false, 'message' => null, 'compact' => false])
{{--
  Nothing here yet.

  `compact` is the one-line form for a panel inside a busy page: a section that
  has no data should cost a line, not a screen. The full form — centred, with a
  large glyph and room around it — is for a page whose whole job is that table.
--}}
@if($compact)
  <p class="tb-empty tb-empty-sm">
    <i class="fas {{ $icon }}" aria-hidden="true"></i>
    {{ $message ?? ($filtered ? "No {$noun} match your filters." : "No {$noun} yet.") }}
    {{ $slot }}
  </p>
@else
  <div class="tb-empty">
    <i class="fas {{ $icon }}" aria-hidden="true"></i>
    <p>{{ $message ?? ($filtered ? "No {$noun} match your filters." : "No {$noun} yet.") }}</p>
    @if($filtered && $attributes->has('wire:click'))
      <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm tb-mt-3" {{ $attributes->only('wire:click') }}>Clear filters</button>
    @endif
    {{ $slot }}
  </div>
@endif
