<div>
  @if($this->existing->isNotEmpty())
    <div class="tb-table-wrap">
      <table class="tb-table">
        <thead><tr><th>Service</th><th class="tb-text-right">Price</th><th class="tb-text-right"><span class="sr-only">Actions</span></th></tr></thead>
        <tbody>
          @foreach($this->existing as $service)
            <tr wire:key="wz-have-svc-{{ $service->id }}">
              <td class="tb-fw-500">{{ $service->name }}</td>
              <td class="tb-text-right"><x-ui.money :amount="$service->price" /></td>
              <td class="tb-text-right">
                <x-ui.icon-button label="Edit {{ $service->name }}" icon="fa-pen" wire:click="edit({{ $service->id }})" />
                <x-ui.icon-button label="Remove {{ $service->name }}" icon="fa-trash" variant="danger"
                                   wire:click="delete({{ $service->id }})" wire:confirm="Remove {{ $service->name }} from the price list?" />
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif

  @if($this->suggestions !== [])
    <div class="tb-mt-4">
      <span class="tb-label">Start from the common list</span>
      <p class="muted tb-small tb-mb-4">Grouped by specialty. Adjust any price, drop any you don't offer, then add the rest.</p>
      @foreach($this->groupedSuggestions() as $category => $rows)
        <div class="wz-cat" wire:key="wz-cat-{{ Str::slug($category) }}">
          <span class="wz-cat-title">{{ $category }}</span>
          <div class="tb-table-wrap">
            <table class="tb-table">
              <thead><tr><th>Service</th><th class="tb-text-right">Price ({{ $currency }})</th><th class="tb-text-right"><span class="sr-only">Remove</span></th></tr></thead>
              <tbody>
                @foreach($rows as $row)
                  <tr wire:key="wz-svc-{{ Str::slug($row['name']) }}">
                    <td class="tb-fw-500">{{ $row['name'] }}</td>
                    <td class="tb-text-right">
                      <label class="sr-only" for="wz-price-{{ Str::slug($row['name']) }}">Price for {{ $row['name'] }}</label>
                      <input id="wz-price-{{ Str::slug($row['name']) }}" type="number" step="0.01" min="0"
                             wire:model="starterPrices.{{ $row['name'] }}" class="tb-input smpl-input">
                      @error('starterPrices.'.$row['name'])<div class="tb-field-error">{{ $message }}</div>@enderror
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
        </div>
      @endforeach
      <div class="wz-actions">
        <button type="button" wire:click="addStarter" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="addStarter">
          <span wire:loading.remove wire:target="addStarter"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i> Add these {{ count($this->suggestions) }} services</span>
          <span wire:loading wire:target="addStarter"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Adding…</span>
        </button>
      </div>
    </div>
  @endif

  <form wire:submit="add" class="tb-mt-4">
    <div class="tb-form-grid">
      <x-ui.field label="Service name" for="wz-svc" name="name" required>
        <input id="wz-svc" type="text" wire:model="name" class="tb-input" placeholder="e.g. Specialist consultation" required>
      </x-ui.field>
      <x-ui.field label="Price ({{ $currency }})" for="wz-svcprice" name="price" required>
        <input id="wz-svcprice" type="number" step="0.01" min="0" wire:model="price" class="tb-input" required>
      </x-ui.field>
    </div>
    <div class="wz-actions">
      <button type="submit" class="btn-tb btn-tb-ghost" wire:loading.attr="disabled" wire:target="add">
        <i class="fas fa-plus" aria-hidden="true"></i> Add this service
      </button>
    </div>
  </form>

  <x-ui.modal show="showEdit" title="Edit service" size="sm">
    @if($showEdit)
      <form wire:submit="saveEdit">
        <div class="tb-modal-body">
          <x-ui.field label="Service name" for="wz-editsvc" name="editName" required>
            <input id="wz-editsvc" type="text" wire:model="editName" class="tb-input" required>
          </x-ui.field>
          <x-ui.field label="Price ({{ $currency }})" for="wz-editsvcprice" name="editPrice" required>
            <input id="wz-editsvcprice" type="number" step="0.01" min="0" wire:model="editPrice" class="tb-input" required>
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
