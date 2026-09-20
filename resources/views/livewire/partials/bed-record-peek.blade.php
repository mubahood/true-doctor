{{-- One bed, over the beds list.

     The list is where beds are priced and retired; what it never said is
     whether any of them earn their keep. Nights stayed and what the bed has
     billed are the two numbers that answer it, and neither existed on a
     screen anywhere. --}}
<x-ui.modal show="showPeek" size="lg" autosaves :title="$this->peeked?->name ?? 'Bed'">
  @if($this->peeked)
    @php($bed = $this->peeked)
    @php($fig = $this->peekedFigures)
    <div class="tb-modal-body">
      <x-ui.peek-head :heading="$bed->name"
                      :sub="($bed->ward?->name ?? 'No ward').' · '.\App\Support\HospitalSettings::money($bed->daily_charge).' a night'">
        <x-ui.badge :tone="$bed->status->badge()">{{ $bed->status->label() }}</x-ui.badge>
        @if(! $bed->is_active)<x-ui.badge tone="danger">Retired</x-ui.badge>@endif
      </x-ui.peek-head>

      <x-ui.peek-figs :figures="[
        ['label' => 'Stays', 'value' => (string) $fig['stays']],
        ['label' => 'Nights slept in', 'value' => (string) $fig['nights'], 'sub' => 'finished stays only'],
        ['label' => 'Billed', 'value' => \App\Support\HospitalSettings::money($fig['earned'])],
      ]" />

      <dl class="tb-peek-facts">
        <dt>Ward</dt><dd>{{ $bed->ward?->name ?? '—' }}</dd>
        <dt>A night</dt><dd>{{ \App\Support\HospitalSettings::money($bed->daily_charge) }}</dd>
        <dt>Right now</dt>
        <dd>
          @if($bed->currentAdmission)
            <span class="tb-fw-500">{{ $bed->currentAdmission->patient?->full_name ?? 'Occupied' }}</span>
            — {{ $bed->currentAdmission->nights() }} {{ Str::plural('night', $bed->currentAdmission->nights()) }}
            since {{ $bed->currentAdmission->admitted_at->format('j M') }}
          @elseif($bed->status->isAssignable())
            <span class="muted">Free — somebody can be admitted to it now</span>
          @else
            <span class="tb-peek-bad">{{ $bed->status->label() }} — nobody can be admitted to it</span>
          @endif
        </dd>
        <dt>Added</dt><dd>{{ $bed->created_at?->format('j M Y') ?? '—' }}</dd>
      </dl>

      <x-ui.peek-trail title="Who has been in it" :rows="$this->peekedStays"
                       empty="Nobody has stayed in this bed yet.">
        @foreach($this->peekedStays as $stay)
          <li wire:key="bed-peek-stay-{{ $stay->id }}">
            <span class="tb-peek-step">
              <span class="tb-fw-500">{{ $stay->patient?->full_name ?? '—' }}</span>
              · {{ $stay->status->label() }}
              · {{ $stay->nights() }} {{ Str::plural('night', $stay->nights()) }}
            </span>
            <span class="tb-peek-meta">
              {{ $stay->admitted_at->format('j M Y') }} →
              {{ $stay->discharged_at?->format('j M Y') ?? 'still here' }}
            </span>
          </li>
        @endforeach
      </x-ui.peek-trail>
    </div>

    <x-ui.peek-foot :href="route('admin.admissions.board')" label="Occupancy board" icon="fa-hospital">
      @can('update', $bed)
        <button type="button" class="btn-tb btn-tb-ghost" wire:click="editPeeked">
          <i class="fas fa-pen" aria-hidden="true"></i> Edit bed
        </button>
      @endcan
    </x-ui.peek-foot>
  @endif
</x-ui.modal>
