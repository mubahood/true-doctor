{{-- One department, over the list.

     The row counts rooms and staff. Anybody reading a count is about to ask
     WHICH, and the answer was not anywhere in the back office. --}}
<x-ui.modal show="showPeek" size="lg" autosaves :title="$this->peeked?->name ?? 'Department'">
  @if($this->peeked)
    @php($dept = $this->peeked)
    <div class="tb-modal-body">
      <x-ui.peek-head :heading="$dept->name" :sub="$dept->code ?: 'No code'">
        <x-ui.badge :tone="$dept->is_active ? 'active' : 'danger'">{{ $dept->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
      </x-ui.peek-head>

      <x-ui.peek-figs :figures="[
        ['label' => 'Rooms', 'value' => (string) $this->peekedRooms->count()],
        ['label' => 'Staff', 'value' => (string) $this->peekedStaff->count(), 'sub' => 'active profiles'],
        ['label' => 'Headed by', 'value' => $dept->head?->name ?? '—'],
      ]" />

      @if($dept->description)
        <div class="tb-peek-sec">What it does</div>
        <p class="tb-peek-note">{{ $dept->description }}</p>
      @endif

      <x-ui.peek-trail title="Its rooms" :rows="$this->peekedRooms"
                       empty="No rooms belong to this department.">
        @foreach($this->peekedRooms as $room)
          <li wire:key="dept-peek-room-{{ $room->id }}">
            <span class="tb-peek-step">
              <span class="tb-fw-500">{{ $room->name }}</span> · {{ $room->type->label() }}
            </span>
            <span class="tb-peek-meta">
              holds {{ $room->capacity }} · {{ strtolower($room->status->label()) }}
            </span>
          </li>
        @endforeach
      </x-ui.peek-trail>

      <x-ui.peek-trail title="Who works in it" :rows="$this->peekedStaff"
                       empty="Nobody has this department on their profile.">
        @foreach($this->peekedStaff as $member)
          <li wire:key="dept-peek-staff-{{ $member->id }}">
            <span class="tb-peek-step">
              <span class="tb-fw-500">{{ $member->user?->name ?? '—' }}</span>
              @if($member->job_title) · {{ $member->job_title }} @endif
            </span>
            <span class="tb-peek-meta">{{ $member->specialty ?: $member->user?->role_label }}</span>
          </li>
        @endforeach
      </x-ui.peek-trail>
    </div>

    <x-ui.peek-foot>
      @can('update', $dept)
        <button type="button" class="btn-tb btn-tb-primary" wire:click="editPeeked">
          <i class="fas fa-pen" aria-hidden="true"></i> Edit department
        </button>
      @endcan
    </x-ui.peek-foot>
  @endif
</x-ui.modal>
