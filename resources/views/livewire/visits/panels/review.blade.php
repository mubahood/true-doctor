<div class="tb-card">
  <div class="tb-card-header">
    <span class="tb-card-title"><i class="fas fa-calendar-plus" aria-hidden="true"></i> Book a review</span>
    <span class="muted tb-xs">Suggested in {{ $this->suggestion['days'] }} days, {{ $this->suggestion['reason'] }}</span>
  </div>
  <div class="tb-card-body">
    @if($this->booked->isNotEmpty())
      <ul class="tb-review-booked">
        @foreach($this->booked as $a)
          <li wire:key="rv-{{ $a->id }}">
            <i class="fas fa-calendar-check" aria-hidden="true"></i>
            <x-ui.link :href="route('admin.appointments.show', $a)">{{ $a->scheduled_at->format('D j M Y, H:i') }}</x-ui.link>
            <span class="muted tb-xs">{{ $a->doctor?->name }}</span>
            <x-ui.badge :tone="$a->status->badge()">{{ $a->status->label() }}</x-ui.badge>
          </li>
        @endforeach
      </ul>
    @endif

    <form wire:submit="book" class="tb-review-form">
      <x-ui.field label="Day" for="rv-date-{{ $visitId }}" name="date">
        <input id="rv-date-{{ $visitId }}" type="date" wire:model.live="date" class="tb-input" min="{{ now()->toDateString() }}">
      </x-ui.field>
      <x-ui.field label="Time" for="rv-time-{{ $visitId }}" name="time">
        <select id="rv-time-{{ $visitId }}" wire:model="time" class="tb-select" @disabled($this->slots === [])>
          <option value="">{{ $this->slots === [] ? 'No free time' : 'Choose' }}</option>
          @foreach($this->slots as $slot)<option value="{{ $slot }}">{{ $slot }}</option>@endforeach
        </select>
      </x-ui.field>
      <button type="submit" class="btn-tb btn-tb-sm btn-tb-primary" wire:loading.attr="disabled" wire:target="book" @disabled($this->slots === [])>
        <i class="fas fa-check" aria-hidden="true"></i> Book review
      </button>
    </form>
    @if($this->slots === [] && ($note = $this->slotsNote()))
      <p class="muted tb-xs tb-mt-2">{{ $note }}</p>
    @endif
  </div>
</div>
