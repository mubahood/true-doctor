<div class="cs-section">
  @if($part === 'done')
    @if($this->data['done'] === [])
      <p class="muted tb-xs cs-empty">Nothing marked done on this visit yet. Press <b>Done &amp; bill</b> on the plan below when a procedure is finished.</p>
    @else
      <ul class="dx-list">
        @foreach($this->data['done'] as $t)
          <li class="dx-item" wire:key="done-{{ $t['uuid'] }}">
            <span class="mono cs-tooth">{{ $t['tooth'] ?? 'Mouth' }}{{ $t['surfaces'] ? ' '.$t['surfaces'] : '' }}</span>
            <span class="dx-title"><b>{{ $t['procedure'] }}</b>@if($t['notes'])<span class="muted"> · {{ $t['notes'] }}</span>@endif</span>
            <span class="mono tb-small">{{ $t['price_label'] }}</span>
            <x-ui.badge tone="success">Billed</x-ui.badge>
          </li>
        @endforeach
      </ul>
    @endif
  @else
    <div class="cs-head">
      <span class="cs-title">Review appointment</span>
      @if(! $booking)
        @can('appointments.manage')
          <span class="cs-acts">
            <button type="button" class="btn-tb btn-tb-sm" wire:click="startBooking" wire:loading.attr="disabled" wire:target="startBooking"><i class="fas fa-calendar-plus" aria-hidden="true"></i> Book review</button>
          </span>
        @endcan
      @endif
    </div>

    @if($this->data['review'])
      <p class="tb-small">Suggested: in {{ $this->data['review']['days'] }} {{ \Illuminate\Support\Str::plural('day', $this->data['review']['days']) }}, {{ \Illuminate\Support\Carbon::parse($this->data['review']['on'])->format('D j M') }} <span class="muted">({{ $this->data['review']['why'] }}, after {{ mb_strtolower($this->data['review']['procedure']) }})</span></p>
    @endif

    @foreach($this->data['booked'] as $b)
      <p class="tb-small" wire:key="rv-{{ $b['uuid'] }}"><i class="fas fa-calendar-check" aria-hidden="true"></i> {{ \Illuminate\Support\Carbon::parse($b['scheduled_at'])->format('D j M Y, H:i') }}@if($b['doctor']) with {{ $b['doctor'] }}@endif <x-ui.badge tone="info">{{ $b['status'] }}</x-ui.badge></p>
    @endforeach

    @if($booking)
      <div class="cs-form">
        <div class="tb-flex">
          <label class="tb-label" for="rv-date">Day</label>
          <input id="rv-date" type="date" class="tb-input tb-input-slim" wire:model.live="date" min="{{ now()->toDateString() }}">
          <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm" wire:click="$set('booking', false)">Cancel</button>
        </div>
        @if($this->slots !== [])
          <div class="cs-chips" role="group" aria-label="Free times">
            @foreach($this->slots as $time)
              <button type="button" class="tb-pill" wire:key="slot-{{ $time }}" wire:click="book('{{ $time }}')" wire:loading.attr="disabled" wire:target="book">{{ $time }}</button>
            @endforeach
          </div>
        @elseif($note)
          <p class="muted tb-xs">{{ $note }}</p>
        @endif
        @error('review')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
      </div>
    @elseif(! $this->data['review'] && $this->data['booked'] === [])
      <p class="muted tb-xs cs-empty">No review booked.</p>
    @endif
  @endif
</div>
