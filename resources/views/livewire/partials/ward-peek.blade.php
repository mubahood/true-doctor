{{-- One ward, over the list.

     "Six beds" is a number somebody is going to follow with "and how many of
     them are free?". The dialog answers that, and then shows the beds so the
     answer can be acted on. --}}
<x-ui.modal show="showPeek" size="lg" autosaves :title="$this->peeked?->name ?? 'Ward'">
  @if($this->peeked)
    @php($ward = $this->peeked)
    @php($fig = $this->peekedFigures)
    <div class="tb-modal-body">
      <x-ui.peek-head :heading="$ward->name" :sub="$ward->description ?: 'No description'">
        <x-ui.badge :tone="$ward->is_active ? 'success' : 'danger'">{{ $ward->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
      </x-ui.peek-head>

      <x-ui.peek-figs :figures="[
        ['label' => 'Beds', 'value' => (string) $fig['beds']],
        ['label' => 'Free now', 'value' => (string) $fig['free'], 'bad' => $fig['free'] === 0],
        ['label' => 'Full', 'value' => $fig['percent'].'%',
         'sub' => $fig['occupied'].' occupied'],
      ]" />

      <dl class="tb-peek-facts">
        <dt>A full night</dt>
        <dd>
          {{ \App\Support\HospitalSettings::money($fig['aNight']) }}
          <span class="muted">if every bed in it were taken</span>
        </dd>
        <dt>Created</dt><dd>{{ $ward->created_at?->format('j M Y') ?? '—' }}</dd>
      </dl>

      <x-ui.peek-trail title="The beds in it" :rows="$this->peekedBeds"
                       empty="This ward has no beds yet.">
        @foreach($this->peekedBeds as $bed)
          <li wire:key="ward-peek-bed-{{ $bed->id }}">
            <span class="tb-peek-step">
              <span class="tb-fw-500">{{ $bed->name }}</span>
              @if($bed->currentAdmission)
                · {{ $bed->currentAdmission->patient?->full_name ?? 'occupied' }}
              @else
                · {{ strtolower($bed->status->label()) }}
              @endif
            </span>
            <span class="tb-peek-meta">
              {{ \App\Support\HospitalSettings::money($bed->daily_charge) }}/night
              @if($bed->currentAdmission)
                · {{ $bed->currentAdmission->nights() }} {{ Str::plural('night', $bed->currentAdmission->nights()) }} so far
              @endif
            </span>
          </li>
        @endforeach
      </x-ui.peek-trail>
    </div>

    <x-ui.peek-foot :href="route('admin.admissions.board', ['ward' => $ward->id])"
                    label="See it on the board" icon="fa-hospital">
      @can('update', $ward)
        <button type="button" class="btn-tb btn-tb-ghost" wire:click="editPeeked">
          <i class="fas fa-pen" aria-hidden="true"></i> Edit ward
        </button>
      @endcan
    </x-ui.peek-foot>
  @endif
</x-ui.modal>
