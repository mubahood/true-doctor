{{-- One stay, over the admissions list.

     "Who, where, since when" is the row. What gets asked next is always the
     same three things — why they came in, who has them, and what the stay has
     cost so far — and every one of them was a page-load away. --}}
<x-ui.modal show="showPeek" size="lg" autosaves
            :title="$this->peeked?->patient?->full_name ?? 'Admission'">
  @if($this->peeked)
    @php($stay = $this->peeked)
    <div class="tb-modal-body">
      <x-ui.peek-head :heading="$stay->patient?->full_name ?? '—'"
                      :sub="($stay->bed?->ward?->name ?? 'No ward').' · '.($stay->bed?->name ?? 'no bed')">
        <x-ui.badge :tone="$stay->status->badge()">{{ $stay->status->label() }}</x-ui.badge>
      </x-ui.peek-head>

      <x-ui.peek-figs :figures="[
        ['label' => $stay->status->isActive() ? 'Nights so far' : 'Nights stayed',
         'value' => (string) $stay->nights(),
         'sub' => 'since '.$stay->admitted_at->format('j M · H:i')],
        ['label' => 'A night',
         'value' => \App\Support\HospitalSettings::money($stay->bed?->daily_charge ?? '0.00')],
        $stay->status->isActive()
          ? ['label' => 'Bed charge so far',
             'value' => \App\Support\HospitalSettings::money($this->accruedSoFar($stay)),
             'sub' => 'billed on discharge']
          : ['label' => 'Bed charge billed',
             'value' => \App\Support\HospitalSettings::money($stay->bed_charge_total)],
      ]" />

      <dl class="tb-peek-facts">
        <dt>Patient no.</dt><dd class="mono">{{ $stay->patient?->patient_no ?? '—' }}</dd>
        @if($stay->patient?->age() !== null || $stay->patient?->sex)
          <dt>Who they are</dt>
          <dd>
            @if($stay->patient->age() !== null){{ $stay->patient->age() }} years old @endif
            @if($stay->patient->sex) · {{ $stay->patient->sex->label() }} @endif
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
        @if(! $stay->status->isActive())
          <dt>Left</dt>
          <dd>
            {{ $stay->discharged_at?->format('j M Y · H:i') ?? '—' }}
            <span class="muted">{{ $stay->discharged_at?->diffForHumans() }}</span>
          </dd>
          @if($stay->discharge_notes)
            <dt>Notes</dt><dd>{{ $stay->discharge_notes }}</dd>
          @endif
        @endif
      </dl>

      <x-ui.peek-trail title="Where they have been moved" :rows="$this->peekedTransfers"
                       empty="They have not been moved since they were admitted.">
        @foreach($this->peekedTransfers as $move)
          <li wire:key="adm-peek-move-{{ $move->id }}">
            <span class="tb-peek-step">
              {{ $move->fromBed?->name ?? 'Nowhere' }} → <span class="tb-fw-500">{{ $move->toBed?->name ?? '—' }}</span>
            </span>
            <span class="tb-peek-meta">{{ $move->created_at?->format('j M · H:i') }}</span>
            @if($move->reason)<span class="tb-peek-note">{{ $move->reason }}</span>@endif
          </li>
        @endforeach
      </x-ui.peek-trail>
    </div>

    <x-ui.peek-foot :href="route('admin.admissions.show', $stay)" label="Full record" icon="fa-folder-open">
      @if($stay->patient)
        <a class="btn-tb btn-tb-ghost" wire:navigate href="{{ route('admin.patients.show', $stay->patient) }}">
          <i class="fas fa-user" aria-hidden="true"></i> The patient
        </a>
      @endif
      @can('manage', \App\Models\Admission::class)
        @if($stay->status->isActive())
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="openTransfer">
            <i class="fas fa-right-left" aria-hidden="true"></i> Transfer
          </button>
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="openDischarge">
            <i class="fas fa-door-open" aria-hidden="true"></i> Discharge
          </button>
        @endif
      @endcan
    </x-ui.peek-foot>
  @endif
</x-ui.modal>

{{-- The two dialogs the quick view hands off to. Each opens once the quick
     view has closed, so nobody ever acts on one stay while reading another. --}}
<x-ui.modal size="md" show="showTransfer" title="Transfer to another bed">
  @if($showTransfer)
    <form wire:submit="transfer" style="display:contents;">
      <div class="tb-modal-body">
        <div class="tb-panel">
          <span class="muted tb-small">
            @if($this->peeked)
              {{ $this->peeked->patient?->full_name }} is in
              {{ $this->peeked->bed?->ward?->name ?? '—' }} · <strong>{{ $this->peeked->bed?->name ?? 'no bed' }}</strong>.
            @else
              The bed this patient is leaving.
            @endif
          </span>
        </div>
        <x-ui.field label="To bed" name="to_bed_id" required hint="Only available, active beds are listed.">
          <livewire:ui.select-search resource="beds-available" name="to_bed_id" :selected="$to_bed_id"
            placeholder="Search available beds or wards…" wire:key="adm-transfer-bed-{{ $moveNonce }}" />
        </x-ui.field>
        <x-ui.field label="Reason" for="adm-transfer-reason" name="transfer_reason">
          <input id="adm-transfer-reason" type="text" class="tb-input" wire:model="transfer_reason" maxlength="255">
        </x-ui.field>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="transfer">
          <span wire:loading.remove wire:target="transfer"><i class="fas fa-right-left" aria-hidden="true"></i> Transfer</span>
          <span wire:loading wire:target="transfer"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Moving…</span>
        </button>
      </div>
    </form>
  @endif
</x-ui.modal>

<x-ui.modal size="md" show="showDischarge" title="Close admission">
  @if($showDischarge)
    <form wire:submit="discharge" style="display:contents;">
      <div class="tb-modal-body">
        <div class="tb-panel">
          <span class="muted tb-small">
            @if($this->peeked)
              {{ $this->peeked->patient?->full_name }} ·
              {{ $this->peeked->nights() }} {{ Str::plural('night', $this->peeked->nights()) }} —
              <strong>{{ \App\Support\HospitalSettings::money($this->accruedSoFar($this->peeked)) }}</strong>
              will be billed to the linked visit, and the bed freed.
            @else
              The stay is billed and the bed freed.
            @endif
          </span>
        </div>
        <x-ui.field label="Outcome" for="adm-discharge-outcome" name="outcome" required>
          <select id="adm-discharge-outcome" class="tb-select" wire:model="outcome" required>
            @foreach(\App\Enums\AdmissionStatus::outcomes() as $o)<option value="{{ $o->value }}">{{ $o->label() }}</option>@endforeach
          </select>
        </x-ui.field>
        <x-ui.field label="Discharge notes" for="adm-discharge-notes" name="discharge_notes">
          <textarea id="adm-discharge-notes" class="tb-input" rows="4" wire:model="discharge_notes" maxlength="5000"></textarea>
        </x-ui.field>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-danger"
          wire:confirm="Close this admission? The bed is freed and the stay is billed. This cannot be undone."
          wire:loading.attr="disabled" wire:target="discharge">
          <span wire:loading.remove wire:target="discharge"><i class="fas fa-door-open" aria-hidden="true"></i> Close admission</span>
          <span wire:loading wire:target="discharge"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Closing…</span>
        </button>
      </div>
    </form>
  @endif
</x-ui.modal>
