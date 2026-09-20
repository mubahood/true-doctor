{{-- One room, over the list. The notes field is the whole reason a room record
     exists and the table has never had a column for it. --}}
<x-ui.modal show="showPeek" size="md" autosaves :title="$this->peeked?->name ?? 'Room'">
  @if($this->peeked)
    @php($room = $this->peeked)
    <div class="tb-modal-body">
      <x-ui.peek-head :heading="$room->name"
                      :sub="$room->type->label().' · '.($room->department?->name ?? 'no department')">
        <x-ui.badge :tone="$room->status->badge()">{{ $room->status->label() }}</x-ui.badge>
      </x-ui.peek-head>

      <x-ui.peek-figs :figures="[
        ['label' => 'Holds', 'value' => (string) $room->capacity,
         'sub' => Str::plural('person', $room->capacity).' at once'],
        ['label' => 'Kind', 'value' => $room->type->label()],
      ]" />

      <dl class="tb-peek-facts">
        <dt>Department</dt><dd>{{ $room->department?->name ?? '—' }}</dd>
        <dt>State</dt>
        <dd>
          {{ $room->status->label() }}
          @unless($room->status->isBookable())
            <span class="tb-peek-bad">— nothing can be booked into it</span>
          @endunless
        </dd>
        <dt>Added</dt><dd>{{ $room->created_at?->format('j M Y') ?? '—' }}</dd>
      </dl>

      <div class="tb-peek-sec">Notes</div>
      @if($room->notes)
        <p class="tb-peek-note">{{ $room->notes }}</p>
      @else
        <p class="tb-out-none">Nothing has been noted about this room.</p>
      @endif
    </div>

    <x-ui.peek-foot>
      @can('update', $room)
        <button type="button" class="btn-tb btn-tb-primary" wire:click="editPeeked">
          <i class="fas fa-pen" aria-hidden="true"></i> Edit room
        </button>
      @endcan
    </x-ui.peek-foot>
  @endif
</x-ui.modal>
