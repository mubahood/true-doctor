{{-- One patient, over the list.

     Six columns of a record that has forty fields. What a desk asks before it
     books anything — are they allergic to anything, who do we ring, do they
     owe us, when were they last here — was none of the six, and the record is
     a workspace with panels of its own. So the questions come here and the
     workspace stays a page. --}}
<x-ui.modal show="showPeek" size="lg" autosaves :title="$this->peeked?->full_name ?? 'Patient'">
  @if($this->peeked)
    @php($p = $this->peeked)
    @php($fig = $this->peekedFigures)
    <div class="tb-modal-body">
      <x-ui.peek-head :heading="$p->full_name"
                      :sub="$p->patient_no.($p->age() !== null ? ' · '.$p->age().' years old' : '').($p->sex ? ' · '.$p->sex->label() : '')">
        <x-ui.badge :tone="$p->status->badge()">{{ $p->status->label() }}</x-ui.badge>
        @if(! $p->consent_given)<x-ui.badge tone="warn">No consent on file</x-ui.badge>@endif
      </x-ui.peek-head>

      <x-ui.peek-figs :figures="[
        ['label' => 'Visits', 'value' => (string) $fig['visits']],
        ['label' => 'Last seen',
         'value' => $fig['lastSeen'] ? \Illuminate\Support\Carbon::parse($fig['lastSeen'])->format('j M Y') : '—',
         'sub' => $fig['lastSeen'] ? \Illuminate\Support\Carbon::parse($fig['lastSeen'])->diffForHumans() : 'never been in'],
        ['label' => 'Owed to the hospital',
         'value' => \App\Support\HospitalSettings::money($fig['owed']),
         'bad' => bccomp((string) $fig['owed'], '0', 2) > 0],
      ]" />

      {{-- The two things that must not be a click away when somebody is about
           to be treated. --}}
      @if($p->allergies || $p->chronic_conditions)
        <div class="tb-peek-sec">Before anything is given</div>
        <dl class="tb-peek-facts">
          <dt>Allergies</dt>
          <dd>
            @if($p->allergies)
              <span class="tb-peek-bad">{{ implode(', ', $p->allergies) }}</span>
            @else
              <span class="muted">None recorded</span>
            @endif
          </dd>
          <dt>Long-term</dt>
          <dd>{{ $p->chronic_conditions ? implode(', ', $p->chronic_conditions) : '—' }}</dd>
          @if($p->blood_type)<dt>Blood</dt><dd class="mono">{{ $p->blood_type }}</dd>@endif
        </dl>
      @endif

      <div class="tb-peek-sec">How to reach them</div>
      <dl class="tb-peek-facts">
        <dt>Phone</dt>
        <dd>
          {{ $p->phone_1 ?: '—' }}@if($p->phone_2) · {{ $p->phone_2 }} @endif
        </dd>
        <dt>Email</dt><dd>{{ $p->email ?: '—' }}</dd>
        <dt>Where</dt>
        <dd>
          {{ $p->address ?: $p->home_address ?: '—' }}@if($p->district) · {{ $p->district->name }} @endif
        </dd>
        <dt>In an emergency</dt>
        <dd>
          @if($p->emergency_contact_name)
            {{ $p->emergency_contact_name }}@if($p->emergency_contact_phone) · {{ $p->emergency_contact_phone }} @endif
          @else
            <span class="tb-peek-bad">Nobody recorded</span>
          @endif
        </dd>
        <dt>Registered</dt>
        <dd>
          {{ $p->created_at?->format('j M Y') ?? '—' }}@if($p->registeredBy) by {{ $p->registeredBy->name }} @endif
        </dd>
      </dl>

      <x-ui.peek-trail title="Recent visits" :rows="$this->peekedVisits"
                       empty="They have never been seen here.">
        @foreach($this->peekedVisits as $visit)
          <li wire:key="pat-peek-visit-{{ $visit->id }}">
            <span class="tb-peek-step">
              <span class="mono">{{ $visit->visit_no }}</span>
              @if($visit->outcome)
                · {{ $visit->outcome->label() }}
              @else
                · {{ $visit->status->label() }}
              @endif
            </span>
            <span class="tb-peek-meta">
              {{ $visit->created_at?->format('j M Y · H:i') }}@if($visit->doctor) · {{ $visit->doctor->name }} @endif
            </span>
          </li>
        @endforeach
      </x-ui.peek-trail>
    </div>

    <x-ui.peek-foot :href="route('admin.patients.show', $p)" label="Full record" icon="fa-folder-open">
      @can('update', $p)
        <button type="button" class="btn-tb btn-tb-ghost" wire:click="editPeeked">
          <i class="fas fa-pen" aria-hidden="true"></i> Edit details
        </button>
      @endcan
      <a class="btn-tb btn-tb-ghost" href="{{ route('admin.patients.id-card', $p) }}" target="_blank" rel="noopener">
        <i class="fas fa-id-card" aria-hidden="true"></i> ID card
      </a>
    </x-ui.peek-foot>
  @endif
</x-ui.modal>
