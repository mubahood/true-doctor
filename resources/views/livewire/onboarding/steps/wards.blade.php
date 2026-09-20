<div>
  @if($this->existing->isNotEmpty())
    <div class="tb-table-wrap">
      <table class="tb-table">
        <thead><tr><th>Ward</th><th class="tb-text-right">Beds</th><th class="tb-text-right"><span class="sr-only">Actions</span></th></tr></thead>
        <tbody>
          @foreach($this->existing as $ward)
            <tr wire:key="wz-have-ward-{{ $ward->id }}">
              <td class="tb-fw-500">{{ $ward->name }}</td>
              <td class="tb-text-right muted">{{ $ward->beds_count }} {{ Str::plural('bed', $ward->beds_count) }}</td>
              <td class="tb-text-right">
                <x-ui.icon-button label="Edit {{ $ward->name }}" icon="fa-pen" wire:click="edit({{ $ward->id }})" />
                <x-ui.icon-button label="Remove {{ $ward->name }}" icon="fa-trash" variant="danger"
                                   wire:click="delete({{ $ward->id }})" wire:confirm="Remove {{ $ward->name }}?" />
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif

  @if($this->suggestions !== [])
    <div class="tb-mt-4">
      <span class="tb-label">Suggested wards</span>
      <p class="muted tb-small tb-mb-4">Common ward types with a starting bed count and daily rate — adjust either, drop any you don't need, then add the rest.</p>
      <div class="tb-table-wrap">
        <table class="tb-table">
          <thead><tr><th>Ward</th><th class="tb-text-right">Beds</th><th class="tb-text-right">Daily rate ({{ $currency }})</th><th class="tb-text-right"><span class="sr-only">Remove</span></th></tr></thead>
          <tbody>
            @foreach($this->suggestions as $row)
              <tr wire:key="wz-ward-{{ Str::slug($row['name']) }}">
                <td class="tb-fw-500">{{ $row['name'] }}</td>
                <td class="tb-text-right">
                  <label class="sr-only" for="wz-beds-{{ Str::slug($row['name']) }}">Beds for {{ $row['name'] }}</label>
                  <input id="wz-beds-{{ Str::slug($row['name']) }}" type="number" min="1" max="50"
                         wire:model="starterBeds.{{ $row['name'] }}" class="tb-input smpl-input">
                </td>
                <td class="tb-text-right">
                  <label class="sr-only" for="wz-rate-{{ Str::slug($row['name']) }}">Daily rate for {{ $row['name'] }}</label>
                  <input id="wz-rate-{{ Str::slug($row['name']) }}" type="number" step="0.01" min="0"
                         wire:model="starterRates.{{ $row['name'] }}" class="tb-input smpl-input">
                  @error('starterRates.'.$row['name'])<div class="tb-field-error">{{ $message }}</div>@enderror
                </td>
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
          <span wire:loading.remove wire:target="addCommon"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i> Add these {{ count($this->suggestions) }} wards</span>
          <span wire:loading wire:target="addCommon"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Adding…</span>
        </button>
      </div>
    </div>
  @endif

  <form wire:submit="add" class="tb-mt-4">
    <div class="tb-form-grid">
      <x-ui.field label="Ward name" for="wz-ward" name="name" required>
        <input id="wz-ward" type="text" wire:model="name" class="tb-input" placeholder="e.g. General ward" required>
      </x-ui.field>
      <x-ui.field label="Description" for="wz-warddesc" name="description" hint="Optional.">
        <input id="wz-warddesc" type="text" wire:model="description" class="tb-input" maxlength="255">
      </x-ui.field>
      <x-ui.field label="Number of beds" for="wz-wardbeds" name="bedsCount" required>
        <input id="wz-wardbeds" type="number" min="1" max="50" wire:model="bedsCount" class="tb-input">
      </x-ui.field>
      <x-ui.field label="Daily rate per bed ({{ $currency }})" for="wz-wardrate" name="dailyCharge" required>
        <input id="wz-wardrate" type="number" step="0.01" min="0" wire:model="dailyCharge" class="tb-input">
      </x-ui.field>
    </div>
    <div class="wz-actions">
      <button type="submit" class="btn-tb btn-tb-ghost" wire:loading.attr="disabled" wire:target="add">
        <i class="fas fa-plus" aria-hidden="true"></i> Add this ward
      </button>
    </div>
  </form>

  <x-ui.modal show="showEdit" title="Edit ward" size="sm">
    @if($showEdit)
      <form wire:submit="saveEdit">
        <div class="tb-modal-body">
          <x-ui.field label="Ward name" for="wz-editward" name="editName" required>
            <input id="wz-editward" type="text" wire:model="editName" class="tb-input" required>
          </x-ui.field>
          <x-ui.field label="Description" for="wz-editwarddesc" name="editDescription" hint="Optional.">
            <input id="wz-editwarddesc" type="text" wire:model="editDescription" class="tb-input" maxlength="255">
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
