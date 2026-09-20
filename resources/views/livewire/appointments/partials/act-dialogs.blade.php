{{-- What can be DONE to an appointment, wherever it is listed.

     The diary and the check-in queue draw the same day in two shapes and both
     need the same two dialogs. Held once — with App\Livewire\Appointments\
     Concerns\ActsOnAppointments behind it — because a second copy is how a
     queue ends up still offering a bare "Completed" button after the diary has
     stopped. --}}
  {{-- ── The outcome: what was done, and what it cost ──────────────
       An appointment used to end with a button that said "Completed" and
       recorded nothing — no findings for the next clinician, no services,
       nothing to bill. It now writes what any other piece of clinical work
       writes: an order on the visit, carrying a report and its items. --}}
  <x-ui.modal show="showOutcome" size="lg"
              :title="'Record outcome · '.($this->outcome?->patient?->full_name ?? 'Appointment')">
    @if($this->outcome)
      @php($a = $this->outcome)
      <form wire:submit="saveOutcome" style="display:contents;">
        <div class="tb-modal-body">
          <p class="tb-ending-who">
            {{ $a->scheduled_at->format('D j M · H:i') }}
            @if($a->doctor) · {{ $a->doctor->name }} @endif
            @if($a->visit) · <b>{{ $a->visit->visit_no }}</b> @else · a visit will be opened @endif
          </p>

          <x-ui.field label="Report" for="appt-report" name="report" required
                      hint="What was found and what was advised. This is the record the next clinician reads.">
            <textarea id="appt-report" wire:model="report" class="tb-textarea" rows="5"
                      placeholder="History, examination, findings, plan…"></textarea>
          </x-ui.field>

          @include('livewire.partials.what-was-used', [
              'lead' => 'What the patient was given',
          ])
        </div>

        <div class="tb-modal-foot tb-out-foot">
          {{-- A consultation is not always written up in one sitting. Saving
               halfway has to be possible, or it gets lost. --}}
          <label class="tb-check-group tb-out-done">
            <input type="checkbox" wire:model.live="completeIt">
            Mark the appointment completed
          </label>

          <button type="button" class="btn-tb btn-tb-ghost" wire:click="closeOutcome">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="saveOutcome">
            <span wire:loading.remove wire:target="saveOutcome">
              <i class="fas fa-check" aria-hidden="true"></i>
              {{ $completeIt ? 'Submit and complete' : 'Save for now' }}
            </span>
            <span wire:loading wire:target="saveOutcome"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Recording…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>

  {{-- ── Ending one: why ───────────────────────────────────────────
       A cancellation is not a status change with a shrug. Somebody rang
       to say they could not come, or did not turn up — and the next
       person to look at this record needs to know which. --}}
  <x-ui.modal show="showEnding" :title="$endingTo === 'no_show' ? 'Mark as no-show' : 'Cancel appointment'">
    @if($this->ending)
    <form wire:submit="endAppointment" style="display:contents;">
      <div class="tb-modal-body">
        <p class="tb-ending-who">
          <b>{{ $this->ending->patient?->full_name ?? 'This appointment' }}</b>
          · {{ $this->ending->scheduled_at->format('D j M · H:i') }}
        </p>
        <x-ui.field label="Why" for="appt-note" name="note"
                    hint="Optional, and kept on the record.">
          <input id="appt-note" type="text" wire:model="note" class="tb-input" maxlength="255"
                 placeholder="{{ $endingTo === 'no_show' ? 'Did not attend…' : 'Patient rang to cancel…' }}">
          <x-ui.suggestions set="note" :current="$note"
                            :options="$endingTo === 'no_show'
                                ? ['Did not attend', 'Arrived too late to be seen']
                                : ['Patient rang to cancel', 'Doctor unavailable', 'Rebooked for another day', 'Patient no longer needs it']" />
        </x-ui.field>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" wire:click="cancelEnding">Keep it</button>
        <button type="submit" class="btn-tb btn-tb-danger" wire:loading.attr="disabled" wire:target="endAppointment">
          <span wire:loading.remove wire:target="endAppointment"><i class="fas fa-ban" aria-hidden="true"></i> {{ $endingTo === 'no_show' ? 'Mark no-show' : 'Cancel it' }}</span>
          <span wire:loading wire:target="endAppointment"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
        </button>
      </div>
    </form>
    @endif
  </x-ui.modal>
