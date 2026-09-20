<div>
  @if($this->existing->isNotEmpty())
    <div class="tb-table-wrap">
      <table class="tb-table">
        <thead><tr><th>Department</th><th>Code</th><th class="tb-text-right"><span class="sr-only">Actions</span></th></tr></thead>
        <tbody>
          @foreach($this->existing as $department)
            <tr wire:key="wz-have-dept-{{ $department->id }}">
              <td class="tb-fw-500">{{ $department->name }}</td>
              <td class="muted">{{ $department->code ?? '—' }}</td>
              <td class="tb-text-right">
                <x-ui.icon-button label="Edit {{ $department->name }}" icon="fa-pen" wire:click="edit({{ $department->id }})" />
                <x-ui.icon-button label="Remove {{ $department->name }}" icon="fa-trash" variant="danger"
                                   wire:click="delete({{ $department->id }})" wire:confirm="Remove {{ $department->name }}?" />
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif

  @if($this->suggestions !== [])
    <div class="tb-mt-4">
      <span class="tb-label">Suggested departments</span>
      <p class="muted tb-small tb-mb-4">Based on what most hospitals run. Drop any you don't need, then add the rest.</p>
      <div class="tb-table-wrap">
        <table class="tb-table">
          <thead><tr><th>Department</th><th>Code</th><th class="tb-text-right"><span class="sr-only">Remove</span></th></tr></thead>
          <tbody>
            @foreach($this->suggestions as $row)
              <tr wire:key="wz-dept-{{ Str::slug($row['name']) }}">
                <td class="tb-fw-500">{{ $row['name'] }}</td>
                <td class="muted">{{ $row['code'] ?? '—' }}</td>
                <td class="tb-text-right">
                  <x-ui.icon-button label="Don't add {{ $row['name'] }}" icon="fa-xmark"
                                     wire:click="removeSuggestion('{{ $row['name'] }}')" />
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <div class="wz-actions">
        <button type="button" wire:click="addCommon" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="addCommon">
          <span wire:loading.remove wire:target="addCommon"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i> Add these {{ count($this->suggestions) }} departments</span>
          <span wire:loading wire:target="addCommon"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Adding…</span>
        </button>
      </div>
    </div>
  @endif

  <form wire:submit="add" class="tb-mt-4">
    <div class="tb-form-grid">
      <x-ui.field label="Department name" for="wz-dept" name="name" required>
        <input id="wz-dept" type="text" wire:model="name" class="tb-input" placeholder="e.g. Dental" required>
      </x-ui.field>
      <x-ui.field label="Short code" for="wz-deptcode" name="code" hint="Optional — shown on schedules.">
        <input id="wz-deptcode" type="text" wire:model="code" class="tb-input" placeholder="DEN" maxlength="24">
      </x-ui.field>
    </div>
    <div class="wz-actions">
      <button type="submit" class="btn-tb btn-tb-ghost" wire:loading.attr="disabled" wire:target="add">
        <i class="fas fa-plus" aria-hidden="true"></i> Add this department
      </button>
    </div>
  </form>

  <x-ui.modal show="showEdit" title="Edit department" size="sm">
    @if($showEdit)
      <form wire:submit="saveEdit">
        <div class="tb-modal-body">
          <x-ui.field label="Department name" for="wz-editdept" name="editName" required>
            <input id="wz-editdept" type="text" wire:model="editName" class="tb-input" required>
          </x-ui.field>
          <x-ui.field label="Short code" for="wz-editdeptcode" name="editCode" hint="Optional — shown on schedules.">
            <input id="wz-editdeptcode" type="text" wire:model="editCode" class="tb-input" maxlength="24">
          </x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="saveEdit">
            <span wire:loading.remove wire:target="saveEdit">Save changes</span>
            <span wire:loading wire:target="saveEdit"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
