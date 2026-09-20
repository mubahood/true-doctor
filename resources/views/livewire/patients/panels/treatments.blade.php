<div class="tb-card">
  <div class="tb-card-header">
    <span class="tb-card-title"><i class="fas fa-notes-medical" aria-hidden="true"></i> Treatment records</span>
    @can('create', \App\Models\TreatmentRecord::class)
      <button type="button" class="btn-tb btn-tb-sm" wire:click="openAdd">
        <i class="fas fa-plus" aria-hidden="true"></i> Add record
      </button>
    @endcan
  </div>

  <div class="tb-table-wrap">
    <table class="tb-table">
      <caption class="sr-only">Treatment records</caption>
      <thead>
        <tr>
          <th>Procedure</th><th>Performed</th><th>By</th><th>Photos</th>
          <th><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        @forelse($this->records as $record)
          <tr wire:key="treat-{{ $record->id }}">
            <td class="tb-fw-500">
              <x-ui.link :href="route('admin.patients.treatments.show', [$this->patient, $record])">{{ $record->procedure }}</x-ui.link>
            </td>
            <td class="muted tb-nowrap">{{ ($record->performed_at ?? $record->created_at)->format('d M Y') }}</td>
            <td class="muted">{{ $record->performedBy?->name ?? '—' }}</td>
            <td class="muted">{{ $record->photos->count() }}</td>
            <td class="tb-text-right">
              @can('delete', $record)
                <x-ui.icon-button label="Remove record {{ $record->procedure }}" icon="fa-trash" variant="danger"
                                  wire:click="delete({{ $record->id }})" wire:confirm="Remove this treatment record?" />
              @endcan
            </td>
          </tr>
        @empty
          <tr><td colspan="5"><x-ui.empty icon="fa-notes-medical" noun="treatment records" /></td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  {{-- ── Slide-over: add a record ────────────────────────────── --}}
  <x-ui.modal size="xl" show="showAdd" title="Add treatment record">
    @if($showAdd)
      <form wire:submit="add" style="display:contents;">
        <div class="tb-modal-body">
          <x-ui.field label="Procedure" for="tr-procedure" name="procedure" required>
            <input id="tr-procedure" type="text" wire:model="procedure" class="tb-input" maxlength="150" required>
          </x-ui.field>
          <x-ui.field label="Performed at" for="tr-performed" name="performed_at" hint="Leave blank to record it as now.">
            <input id="tr-performed" type="datetime-local" wire:model="performed_at" class="tb-input">
          </x-ui.field>
          <x-ui.field label="Notes" for="tr-notes" name="description">
            <textarea id="tr-notes" wire:model="description" class="tb-textarea" rows="3" maxlength="5000"></textarea>
          </x-ui.field>
          <x-ui.field label="Photos" for="tr-photos" name="photos" hint="Up to 12 images, 8 MB each.">
            <input id="tr-photos" type="file" wire:model.live="photos" class="tb-input" accept="image/*" multiple>
            <div wire:loading wire:target="photos" class="muted tb-small tb-mt-2">
              <i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Uploading…
            </div>
            @error('photos.*')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
            @if($photos)
              <div class="tb-flex tb-mt-2">
                @foreach($photos as $index => $photo)
                  <div class="tb-text-center" wire:key="photo-{{ $index }}">
                    <img src="{{ $photo->temporaryUrl() }}" alt="Preview {{ $index + 1 }}" width="72" height="72">
                    <x-ui.icon-button label="Remove photo {{ $index + 1 }}" icon="fa-xmark" wire:click="removePhoto({{ $index }})" />
                  </div>
                @endforeach
              </div>
            @endif
          </x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="add,photos">
            <span wire:loading.remove wire:target="add"><i class="fas fa-check" aria-hidden="true"></i> Add record</span>
            <span wire:loading wire:target="add"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
