{{-- One clinician, over the staff list.

     The row abbreviates everything that matters: qualifications are cut off, a
     licence number is a string with no context, and nothing says whether the
     person can be BOOKED — which is the first thing asked before an
     appointment is offered to a patient. --}}
<x-ui.modal show="showPeek" size="lg" autosaves
            :title="$this->peeked?->user?->name ?? 'Staff profile'">
  @if($this->peeked)
    @php($sp = $this->peeked)
    <div class="tb-modal-body">
      <x-ui.peek-head :heading="$sp->user?->name ?? '—'"
                      :sub="trim(($sp->job_title ?: ($sp->user?->role_label ?? '')).' · '.($sp->department?->name ?? 'no department'), ' ·')">
        <x-ui.badge :tone="$sp->is_active ? 'active' : 'danger'">{{ $sp->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
        @if($this->peekedWindows->isEmpty())<x-ui.badge tone="warn">Not bookable</x-ui.badge>@endif
      </x-ui.peek-head>

      <x-ui.peek-figs :figures="[
        ['label' => 'Bookable windows', 'value' => (string) $this->peekedWindows->count(),
         'bad' => $this->peekedWindows->isEmpty()],
        ['label' => 'Booked ahead', 'value' => (string) $this->peekedUpcoming,
         'sub' => 'from today on'],
        ['label' => 'Department', 'value' => $sp->department?->name ?? '—'],
      ]" />

      <dl class="tb-peek-facts">
        <dt>Job title</dt><dd>{{ $sp->job_title ?: '—' }}</dd>
        <dt>Specialty</dt><dd>{{ $sp->specialty ?: '—' }}</dd>
        <dt>Licence</dt><dd class="mono">{{ $sp->license_no ?: '—' }}</dd>
        <dt>Qualifications</dt><dd>{{ $sp->qualifications ?: '—' }}</dd>
        <dt>Signs as</dt>
        <dd>
          @if($sp->signature)
            {{ $sp->signature }}
          @else
            <span class="muted">Nothing set — printed reports carry their name alone</span>
          @endif
        </dd>
        <dt>Account</dt>
        <dd>
          {{ $sp->user?->email ?? '—' }}
          @if($sp->user && ! $sp->user->is_active)
            <span class="tb-peek-bad">— the login is switched off</span>
          @endif
        </dd>
      </dl>

      {{-- The roster, which decides whether a patient can be offered a time. --}}
      <x-ui.peek-trail title="When they are bookable" :rows="$this->peekedWindows"
                       empty="No windows on the roster — nothing can be booked with them.">
        @foreach($this->peekedWindows as $window)
          <li wire:key="staff-peek-win-{{ $window->id }}">
            <span class="tb-peek-step">
              <span class="tb-fw-500">{{ $window->weekday->label() }}</span>
              <span class="mono">{{ substr($window->start_time, 0, 5) }}–{{ substr($window->end_time, 0, 5) }}</span>
              @unless($window->is_active)<span class="tb-peek-bad">switched off</span>@endunless
            </span>
            <span class="tb-peek-meta">
              {{ $window->slot_minutes }} min slots
              @if($window->room) · {{ $window->room->name }} @endif
            </span>
          </li>
        @endforeach
      </x-ui.peek-trail>
    </div>

    <x-ui.peek-foot :href="route('admin.schedules.index')" label="The roster" icon="fa-calendar-days">
      @can('update', $sp)
        <button type="button" class="btn-tb btn-tb-ghost" wire:click="editPeeked">
          <i class="fas fa-pen" aria-hidden="true"></i> Edit profile
        </button>
      @endcan
    </x-ui.peek-foot>
  @endif
</x-ui.modal>
