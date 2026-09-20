{{-- Blocking a device.

     The reason is required, because the device shows it to whoever next opens
     it — "this device has been blocked" with no explanation is how a clinician
     decides the system is broken and starts writing on paper. --}}
<x-ui.modal size="md" show="showRevoke" title="Block this device">
  @if($showRevoke)
    @php($device = $revokingId ? \App\Models\Device::find($revokingId) : null)
    <form wire:submit="revoke" style="display:contents;">
      <div class="tb-modal-body">
        <div class="tb-panel">
          <span class="muted tb-small">
            @if($device)
              <strong>{{ $device->label }}</strong>@if($device->user) · used by {{ $device->user->name }}@endif.
            @endif
            It will stop syncing immediately, and its local copy is cleared the next time it reaches
            the server. Work it has not yet sent will be lost — so if the machine is reachable, let it
            sync first.
          </span>
        </div>

        <x-ui.field label="Why are you blocking it?" for="revoke-reason" name="revoke_reason" required
                    hint="Shown on the device. Write it for whoever picks that machine up next.">
          <textarea id="revoke-reason" class="tb-textarea" rows="3" wire:model="revoke_reason"
                    maxlength="255" placeholder="This laptop was reported lost on 19 September."></textarea>
          <x-ui.suggestions set="revoke_reason" :current="$revoke_reason" :options="[
            'This device was lost or stolen.',
            'The person who used it has left.',
            'It was set up by mistake.',
            'It is being taken out of clinical use.',
          ]" />
        </x-ui.field>
      </div>

      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-danger"
                wire:confirm="Block this device? Anything it has not sent will be lost."
                wire:loading.attr="disabled" wire:target="revoke">
          <span wire:loading.remove wire:target="revoke"><i class="fas fa-ban" aria-hidden="true"></i> Block it</span>
          <span wire:loading wire:target="revoke"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Blocking…</span>
        </button>
      </div>
    </form>
  @endif
</x-ui.modal>
