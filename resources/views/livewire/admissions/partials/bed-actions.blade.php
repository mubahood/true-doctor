{{-- The three things the board can do to a bed.

     Each opens after the quick view has CLOSED, so nobody ever acts on one bed
     while reading another. The transfer and discharge are the same
     MovesPatientsBetweenBeds actions the admission workspace runs — the same
     validation, the same service, the same locks. --}}

{{-- Admitting is the shared dialog — the same one the admissions list uses —
     so a stay raised from the board is raised exactly as one raised from the
     list: as an order on a visit, with the bed already filled in. --}}
@include('livewire.partials.admit-dialog')

{{-- ── Transfer the patient out of it ─────────────────────────── --}}
<x-ui.modal size="md" show="showTransfer" title="Transfer to another bed">
  @if($showTransfer)
    <form wire:submit="transfer" style="display:contents;">
      <div class="tb-modal-body">
        <div class="tb-panel">
          <span class="muted tb-small">
            @if($this->peeked)
              Currently in {{ $this->peeked->ward?->name ?? '—' }} · <strong>{{ $this->peeked->name }}</strong>.
            @else
              The bed this patient is leaving.
            @endif
          </span>
        </div>

        <x-ui.field label="To bed" name="to_bed_id" required hint="Only available, active beds are listed.">
          <livewire:ui.select-search resource="beds-available" name="to_bed_id" :selected="$to_bed_id"
            placeholder="Search available beds or wards…" wire:key="board-transfer-bed-{{ $moveNonce }}" />
        </x-ui.field>

        <x-ui.field label="Reason" for="board-transfer-reason" name="transfer_reason">
          <input id="board-transfer-reason" type="text" class="tb-input" wire:model="transfer_reason" maxlength="255">
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

{{-- ── Close the stay (pessimistic — it bills it) ─────────────── --}}
<x-ui.modal size="md" show="showDischarge" title="Close admission">
  @if($showDischarge)
    @php($closing = $this->peeked?->currentAdmission)
    <form wire:submit="discharge" style="display:contents;">
      <div class="tb-modal-body">
        <div class="tb-panel">
          <span class="muted tb-small">
            @if($closing && $this->peeked)
              {{ $closing->patient?->full_name }} ·
              {{ $closing->nights() }} {{ Str::plural('night', $closing->nights()) }} at
              {{ \App\Support\HospitalSettings::money($this->peeked->daily_charge) }} a night —
              <strong>{{ \App\Support\HospitalSettings::money($this->accruedSoFar($this->peeked, $closing)) }}</strong>
              will be billed to the linked visit, and the bed freed.
            @else
              The stay is billed and the bed freed.
            @endif
          </span>
        </div>

        <x-ui.field label="Outcome" for="board-discharge-outcome" name="outcome" required>
          <select id="board-discharge-outcome" class="tb-select" wire:model="outcome" required>
            @foreach($outcomes as $o)<option value="{{ $o->value }}">{{ $o->label() }}</option>@endforeach
          </select>
        </x-ui.field>

        <x-ui.field label="Discharge notes" for="board-discharge-notes" name="discharge_notes">
          <textarea id="board-discharge-notes" class="tb-input" rows="4" wire:model="discharge_notes" maxlength="5000"></textarea>
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
