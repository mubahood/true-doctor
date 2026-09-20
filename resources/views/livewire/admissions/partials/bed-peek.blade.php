{{-- One bed, over the board.

     A tile can say "Bed 4 · Joseph Okello · 11 nights" and no more. What the
     ward actually asks about a bed is the rest: why they came in, who admitted
     them, whether they have been moved, what the stay has cost so far — and,
     on an empty bed, whether it has been turned round since the last patient.

     None of that was worth a page-load, so none of it was ever looked at. --}}
<x-ui.modal show="showPeek" size="lg" autosaves :title="$this->peeked?->name ?? 'Bed'">
  @if($this->peeked)
    @php($bed = $this->peeked)
    @php($stay = $bed->currentAdmission)
    <div class="tb-modal-body">
      <x-ui.peek-head :heading="$bed->name"
                      :sub="($bed->ward?->name ?? 'No ward').' · '.\App\Support\HospitalSettings::money($bed->daily_charge).' a night'">
        <x-ui.badge :tone="$bed->status->badge()">{{ $bed->status->label() }}</x-ui.badge>
        @if(! $bed->is_active)<x-ui.badge tone="danger">Retired</x-ui.badge>@endif
      </x-ui.peek-head>

      @if($stay)
        {{-- The stay, in the three figures a ward round asks for. The last one
             is money accruing right now and was nowhere on this screen. --}}
        <x-ui.peek-figs :figures="[
          ['label' => 'Nights so far', 'value' => (string) $stay->nights(),
           'sub' => 'since '.$stay->admitted_at->format('j M · H:i')],
          ['label' => 'A night', 'value' => \App\Support\HospitalSettings::money($bed->daily_charge)],
          ['label' => 'Bed charge so far', 'value' => \App\Support\HospitalSettings::money($this->accruedSoFar($bed, $stay)),
           'sub' => 'billed on discharge'],
        ]" />

        <dl class="tb-peek-facts">
          <dt>Patient</dt>
          <dd>
            <span class="tb-fw-500">{{ $stay->patient?->full_name ?? '—' }}</span>
            <span class="mono">{{ $stay->patient?->patient_no }}</span>
          </dd>
          @if($stay->patient?->age() !== null || $stay->patient?->sex)
            <dt>Who they are</dt>
            <dd>
              @if($stay->patient->age() !== null){{ $stay->patient->age() }} years old @endif
              @if($stay->patient->sex) · {{ $stay->patient->sex->label() }}@endif
            </dd>
          @endif
          <dt>Admitted</dt>
          <dd>{{ $stay->admitted_at->format('j M Y · H:i') }} <span class="muted">{{ $stay->admitted_at->diffForHumans() }}</span></dd>
          <dt>Under</dt><dd>{{ $stay->admittingDoctor?->name ?? '—' }}</dd>
          <dt>Why</dt><dd>{{ $stay->reason ?: '—' }}</dd>
          <dt>Visit</dt>
          <dd>
            @if($stay->visit)
              <span class="mono">{{ $stay->visit->visit_no }}</span>
              <span class="muted">— the stay is billed onto it</span>
            @else
              <span class="muted">Not linked to a visit</span>
            @endif
          </dd>
        </dl>

        {{-- A stay that has been through three beds is a different
             conversation from one that has not moved. --}}
        <x-ui.peek-trail title="Where they have been moved" :rows="$this->peekedTransfers"
                         empty="They have been in this bed since they were admitted.">
          @foreach($this->peekedTransfers as $move)
            <li wire:key="peek-move-{{ $move->id }}">
              <span class="tb-peek-step">
                {{ $move->fromBed?->name ?? 'Nowhere' }} → <span class="tb-fw-500">{{ $move->toBed?->name ?? '—' }}</span>
              </span>
              <span class="tb-peek-meta">{{ $move->created_at?->format('j M · H:i') }}</span>
              @if($move->reason)<span class="tb-peek-note">{{ $move->reason }}</span>@endif
            </li>
          @endforeach
        </x-ui.peek-trail>
      @else
        <x-ui.peek-figs :figures="[
          ['label' => 'A night', 'value' => \App\Support\HospitalSettings::money($bed->daily_charge)],
          ['label' => 'State', 'value' => $bed->status->label(),
           'bad' => $bed->status === \App\Enums\BedStatus::Maintenance],
          $this->peekedLastStay
            ? ['label' => 'Free since', 'value' => $this->peekedLastStay->discharged_at?->format('j M') ?? '—',
               'sub' => $this->peekedLastStay->discharged_at?->diffForHumans()]
            : ['label' => 'Free since', 'value' => '—', 'sub' => 'nobody has stayed in it'],
        ]" />

        <dl class="tb-peek-facts">
          <dt>Ward</dt><dd>{{ $bed->ward?->name ?? '—' }}</dd>
          <dt>Takes a patient</dt>
          <dd>
            @if($bed->status->isAssignable())
              Yes — somebody can be admitted to it now
            @else
              <span class="tb-peek-bad">No — it is {{ strtolower($bed->status->label()) }}</span>
            @endif
          </dd>
        </dl>

        {{-- "Is it free" is what the tile said. "Has it been turned round" is
             what somebody walking towards it needs to know. --}}
        <x-ui.peek-trail title="Last patient in it" :rows="$this->peekedLastStay ? [$this->peekedLastStay] : []"
                         empty="Nobody has stayed in this bed yet.">
          @if($this->peekedLastStay)
            @php($last = $this->peekedLastStay)
            <li>
              <span class="tb-peek-step">
                <span class="tb-fw-500">{{ $last->patient?->full_name ?? '—' }}</span>
                · {{ $last->status->label() }}
                · {{ $last->nights() }} {{ Str::plural('night', $last->nights()) }}
              </span>
              <span class="tb-peek-meta">
                left {{ $last->discharged_at?->format('j M Y · H:i') }}
                @if($last->discharged_at) · {{ $last->discharged_at->diffForHumans() }}@endif
              </span>
              @if($last->discharge_notes)<span class="tb-peek-note">{{ $last->discharge_notes }}</span>@endif
            </li>
          @endif
        </x-ui.peek-trail>
      @endif
    </div>

    <x-ui.peek-foot :href="$stay ? route('admin.admissions.show', $stay) : null" label="The admission" icon="fa-user-injured">
      @can('manage', \App\Models\Admission::class)
        @if($stay)
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="openTransfer">
            <i class="fas fa-right-left" aria-hidden="true"></i> Transfer
          </button>
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="openDischarge">
            <i class="fas fa-door-open" aria-hidden="true"></i> Discharge
          </button>
        @elseif($bed->status->isAssignable())
          <button type="button" class="btn-tb btn-tb-primary" wire:click="openAdmitHere">
            <i class="fas fa-user-plus" aria-hidden="true"></i> Admit into it
          </button>
        @endif
      @endcan

      @can('update', $bed)
        @if(! $stay)
          @if($bed->status === \App\Enums\BedStatus::Maintenance)
            <button type="button" class="btn-tb btn-tb-ghost" wire:click="setBedStatus('available')">
              <i class="fas fa-rotate-left" aria-hidden="true"></i> Back in service
            </button>
          @else
            <button type="button" class="btn-tb btn-tb-ghost" wire:click="setBedStatus('maintenance')"
                    wire:confirm="Take this bed out of service? Nobody can be admitted to it until it is put back.">
              <i class="fas fa-screwdriver-wrench" aria-hidden="true"></i> Out of service
            </button>
          @endif
        @endif
      @endcan
    </x-ui.peek-foot>
  @endif
</x-ui.modal>
