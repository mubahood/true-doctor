<div class="tb-card">
  <div class="tb-card-header">
    <span class="tb-card-title"><i class="fas fa-folder-open" aria-hidden="true"></i> Documents</span>
    @can('manageDocuments', $this->patient)
      <button type="button" class="btn-tb btn-tb-sm" wire:click="openUpload">
        <i class="fas fa-upload" aria-hidden="true"></i> Upload
      </button>
    @endcan
  </div>

  <div class="tb-table-wrap">
    <table class="tb-table">
      <caption class="sr-only">Patient documents</caption>
      <thead>
        <tr>
          <th>Type</th><th>File</th><th>Size</th><th>Uploaded</th>
          <th><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        @forelse($this->documents as $doc)
          <tr wire:key="doc-{{ $doc->id }}">
            <td><x-ui.badge tone="info">{{ ucfirst($doc->type) }}</x-ui.badge></td>
            <td>
              <x-ui.link :href="route('admin.patients.documents.download', [$this->patient, $doc])" :navigate="false">{{ $doc->original_name }}</x-ui.link>
              @if($doc->note)<div class="muted tb-xs">{{ $doc->note }}</div>@endif
            </td>
            <td class="muted tb-nowrap">{{ number_format(($doc->size ?? 0) / 1024, 0) }} KB</td>
            <td class="muted tb-nowrap">
              {{ $doc->created_at->format('d M Y') }}@if($doc->uploader) · {{ $doc->uploader->name }}@endif
            </td>
            <td class="tb-text-right">
              @can('manageDocuments', $this->patient)
                <x-ui.icon-button label="Remove {{ $doc->original_name }}" icon="fa-trash" variant="danger"
                                  wire:click="delete({{ $doc->id }})" wire:confirm="Remove this document?" />
              @endcan
            </td>
          </tr>
        @empty
          <tr><td colspan="5"><x-ui.empty icon="fa-folder-open" noun="documents uploaded" /></td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  {{-- ── Slide-over: upload ──────────────────────────────────── --}}
  <x-ui.modal size="md" show="showUpload" title="Upload document">
    @if($showUpload)
      <form wire:submit="upload" style="display:contents;">
        <div class="tb-modal-body">
          <x-ui.field label="Type" for="doc-type" name="type" required>
            <select id="doc-type" wire:model="type" class="tb-select" required>
              @foreach($this->types as $docType)
                <option value="{{ $docType }}">{{ ucfirst($docType) }}</option>
              @endforeach
            </select>
          </x-ui.field>
          <x-ui.field label="File" for="doc-file" name="file" required hint="PDF, image or Word document up to 10 MB.">
            <input id="doc-file" type="file" wire:model.live="file" class="tb-input" required>
            <div wire:loading wire:target="file" class="muted tb-small tb-mt-2">
              <i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Uploading…
            </div>
            @if($file)
              <div class="muted tb-xs tb-mt-2"><i class="fas fa-paperclip" aria-hidden="true"></i> {{ $file->getClientOriginalName() }}</div>
            @endif
          </x-ui.field>
          <x-ui.field label="Note" for="doc-note" name="note">
            <input id="doc-note" type="text" wire:model="note" class="tb-input" maxlength="255">
          </x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="upload,file">
            <span wire:loading.remove wire:target="upload"><i class="fas fa-check" aria-hidden="true"></i> Upload</span>
            <span wire:loading wire:target="upload"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Uploading…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
