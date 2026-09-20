@props([
    'steps' => [],         // list of ['label','count','href','tone'=>'','note'=>null]
    'blocked' => 0,        // visits whose work is unfinished
])
{{--
  The visit pipeline, in the order a visit moves through it (docs/visits.md).

  Not a bar chart: these are stages of one journey, and what a reader wants to
  know is where people are PILING UP, which a chart sorted by size hides. So the
  order is fixed — not started, in care, billing, payment — and each step is a
  link into the list filtered to exactly that stage.
--}}
<div class="tb-card-body">
  <div class="dash-flow">
    @foreach($steps as $step)
      <a class="dash-flow-step {{ ($step['count'] ?? 0) > 0 ? 'has' : '' }} {{ $step['tone'] ?? '' }}"
         wire:navigate href="{{ $step['href'] }}">
        <span class="n">{{ number_format($step['count'] ?? 0) }}</span>
        <span class="l">{{ $step['label'] }}</span>
        @if(! empty($step['note']))<span class="s">{{ $step['note'] }}</span>@endif
      </a>
      @unless($loop->last)
        <i class="dash-flow-arrow fas fa-chevron-right" aria-hidden="true"></i>
      @endunless
    @endforeach
  </div>

  {{-- The one number on this panel anybody can act on this morning. --}}
  @if($blocked > 0)
    <p class="dash-flow-note">
      <i class="fas fa-lock" aria-hidden="true"></i>
      {{ $blocked }} {{ Str::plural('visit', $blocked) }}
      {{ $blocked === 1 ? 'cannot' : 'cannot' }} move to billing — work is still open on
      {{ $blocked === 1 ? 'it' : 'them' }}.
    </p>
  @endif
</div>
