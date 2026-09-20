<div>
  @if($this->existing->isNotEmpty())
    <div class="tb-table-wrap">
      <table class="tb-table">
        <thead><tr><th>Room</th><th>Type</th><th class="tb-text-right"><span class="sr-only">Actions</span></th></tr></thead>
        <tbody>
          @foreach($this->existing as $room)
            <tr wire:key="wz-have-room-{{ $room->id }}">
              <td class="tb-fw-500">{{ $room->name }}</td>
              <td class="muted">{{ $this->typeOptions()[$room->type->value] ?? $room->type->value }}</td>
              <td class="tb-text-right">
                <x-ui.icon-button label="Edit {{ $room->name }}" icon="fa-pen" wire:click="edit({{ $room->id }})" />
                <x-ui.icon-button label="Remove {{ $room->name }}" icon="fa-trash" variant="danger"
                                   wire:click="delete({{ $room->id }})" wire:confirm="Remove {{ $room->name }}?" />
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif

  @if($this->suggestions !== [])
    <div class="tb-mt-4">
      <span class="tb-label">Suggested rooms</span>
      <p class="muted tb-small tb-mb-4">A small starter set for a general clinic. Drop any you don't need, then add the rest.</p>
      <div class="tb-table-wrap">
        <table class="tb-table">
          <thead><tr><th>Room</th><th>Type</th><th class="tb-text-right"><span class="sr-only">Remove</span></th></tr></thead>
          <tbody>
            @foreach($this->suggestions as $row)
              <tr wire:key="wz-room-{{ Str::slug($row['name']) }}">
                <td class="tb-fw-500">{{ $row['name'] }}</td>
                <td class="muted">{{ $this->typeOptions()[$row['type']] ?? $row['type'] }}</td>
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
          <span wire:loading.remove wire:target="addCommon"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i> Add these {{ count($this->suggestions) }} rooms</span>
          <span wire:loading wire:target="addCommon"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Adding…</span>
        </button>
      </div>
    </div>
  @endif

  <form wire:submit="add" class="tb-mt-4">
    <div class="tb-form-grid">
      <x-ui.field label="Room name" for="wz-room" name="name" required>
        <input id="wz-room" type="text" wire:model="name" class="tb-input" placeholder="e.g. Consultation Room 3" required>
      </x-ui.field>
      <x-ui.field label="Type" for="wz-roomtype" name="type" required>
        <select id="wz-roomtype" wire:model="type" class="tb-select">
          @foreach($this->typeOptions() as $value => $label)
            <option value="{{ $value }}">{{ $label }}</option>
          @endforeach
        </select>
      </x-ui.field>
      <x-ui.field label="Capacity" for="wz-roomcap" name="capacity" hint="Patients seen in it at once." required>
        <input id="wz-roomcap" type="number" min="1" max="9999" wire:model="capacity" class="tb-input">
      </x-ui.field>
    </div>
    <div class="wz-actions">
      <button type="submit" class="btn-tb btn-tb-ghost" wire:loading.attr="disabled" wire:target="add">
        <i class="fas fa-plus" aria-hidden="true"></i> Add this room
      </button>
    </div>
  </form>

  <x-ui.modal show="showEdit" title="Edit room" size="sm">
    @if($showEdit)
      <form wire:submit="saveEdit">
        <div class="tb-modal-body">
          <x-ui.field label="Room name" for="wz-editroom" name="editName" required>
            <input id="wz-editroom" type="text" wire:model="editName" class="tb-input" required>
          </x-ui.field>
          <x-ui.field label="Type" for="wz-editroomtype" name="editType" required>
            <select id="wz-editroomtype" wire:model="editType" class="tb-select">
              @foreach($this->typeOptions() as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
              @endforeach
            </select>
          </x-ui.field>
          <x-ui.field label="Capacity" for="wz-editroomcap" name="editCapacity" required>
            <input id="wz-editroomcap" type="number" min="1" max="9999" wire:model="editCapacity" class="tb-input">
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
