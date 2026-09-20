{{-- Admitting a patient — the one dialog, shared by the admissions list and
     the occupancy board.

     A stay is an ORDER ON A VISIT: AdmissionService places it as an Admission
     order, in progress from the moment the patient is in the bed, and it stays
     open until discharge — which is what holds the visit's gate shut while
     somebody is still in a bed. All of that was already true and none of it
     was on the screen, so the visit appeared out of nowhere afterwards and the
     board's own admit did not attach one at all. --}}
@php($admitVisit = $showAdmit ? $this->admitVisit() : null)
<x-ui.modal size="md" show="showAdmit" title="Admit patient">
  @if($showAdmit)
    <form wire:submit="{{ $admitAction ?? 'admit' }}" style="display:contents;">
      <div class="tb-modal-body">
        {{-- ── The visit comes first ──────────────────────────────────
             A stay is an ORDER ON A VISIT, raised the same way a lab or an
             imaging order is. So the visit is what you choose, and the
             patient is whoever it belongs to — asking for both invited them
             to disagree, and the service had to refuse the combination.

             Searching this by a patient's name works: the picker matches on
             the patient's name and number as well as the visit number, and
             labels each row with both. --}}
        <x-ui.field label="Visit" name="visit_id" required
                    hint="Search by the patient's name or number. The stay becomes an order on this visit and stays open until discharge.">
          <livewire:ui.select-search resource="visits" name="visit_id" :selected="$visit_id"
            placeholder="Search by patient or visit number…"
            wire:key="admit-visit-{{ $admitNonce }}" />
        </x-ui.field>

        @if($admitVisit)
          <div class="tb-panel">
            <span class="muted tb-small">
              <i class="fas fa-user" aria-hidden="true"></i>
              Admitting <strong>{{ $admitVisit->patient?->full_name }}</strong>
              @if($admitVisit->patient?->patient_no)
                <span class="mono">({{ $admitVisit->patient->patient_no }})</span>
              @endif
              on visit <strong class="mono">{{ $admitVisit->visit_no }}</strong>,
              opened {{ $admitVisit->created_at?->format('j M Y') }}@if($admitVisit->doctor) under {{ $admitVisit->doctor->name }}@endif.
              A night in the bed is added to it each morning, priced at what the bed costs that day.
            </span>
          </div>
        @else
          {{-- No visit, no admission — the same rule every other order
               follows. Said with the way out beside it, because a patient
               who has just arrived genuinely has no visit yet. --}}
          <div class="tb-panel">
            <span class="muted tb-small">
              <i class="fas fa-circle-info" aria-hidden="true"></i>
              A stay is raised on a visit, so choose the one the patient is being seen on.
              If they have only just arrived,
              <a wire:navigate href="{{ route('admin.visits.index') }}">open a visit for them first</a>.
            </span>
          </div>
        @endif

        <x-ui.field label="Bed" name="bed_id" required hint="Only available, active beds are listed.">
          <livewire:ui.select-search resource="beds-available" name="bed_id" :selected="$bed_id"
            placeholder="Search available beds or wards…"
            wire:key="admit-bed-{{ $admitNonce }}" />
        </x-ui.field>
        <x-ui.field label="Admitting doctor" name="admitting_doctor_id"
                    hint="Who is taking them on. The stay's order is assigned to them.">
          <livewire:ui.select-search resource="doctors" name="admitting_doctor_id" :selected="$admitting_doctor_id"
            placeholder="Search doctors…" wire:key="admit-doctor-{{ $admitNonce }}" />
        </x-ui.field>

        <x-ui.field label="What the stay is called" for="admit-title" name="stay_title"
                    hint="How it appears in the visit's list of work.">
          <input id="admit-title" type="text" class="tb-input" wire:model="stay_title" maxlength="120"
                 {{-- PHP refuses a trait constant read off the trait; it lives on the
                      component that uses it. --}}
                 placeholder="{{ $this::DEFAULT_STAY_TITLE }}">
          <x-ui.suggestions set="stay_title" :current="$stay_title" :options="[
            'Inpatient stay', 'Post-operative stay', 'Observation', 'Maternity stay', 'Day case',
          ]" />
        </x-ui.field>

        <x-ui.field label="Reason for admission" for="admit-reason" name="reason">
          <textarea id="admit-reason" class="tb-textarea" rows="2" wire:model="reason" maxlength="255"></textarea>
        </x-ui.field>
      </div>

      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary"
                wire:loading.attr="disabled" wire:target="{{ $admitAction ?? 'admit' }}">
          <span wire:loading.remove wire:target="{{ $admitAction ?? 'admit' }}"><i class="fas fa-check" aria-hidden="true"></i> Admit patient</span>
          <span wire:loading wire:target="{{ $admitAction ?? 'admit' }}"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Admitting…</span>
        </button>
      </div>
    </form>
  @endif
</x-ui.modal>
