<div class="tb-card">
  <div class="tb-card-header">
    <span class="tb-card-title"><i class="fas fa-shield-heart" aria-hidden="true"></i> Insurance coverage</span>
    @can('manage', \App\Models\InsuranceClaim::class)
      <button type="button" class="btn-tb btn-tb-sm" wire:click="openAdd">
        <i class="fas fa-plus" aria-hidden="true"></i> Add coverage
      </button>
    @endcan
  </div>

  <div class="tb-table-wrap">
    <table class="tb-table">
      <caption class="sr-only">Insurance coverage</caption>
      <thead>
        <tr>
          <th>Provider</th><th>Member no.</th><th>Coverage</th><th>Valid to</th>
          <th><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        @forelse($this->coverages as $coverage)
          <tr wire:key="cov-{{ $coverage->id }}">
            <td class="tb-fw-500">{{ $coverage->provider?->name ?? '—' }}</td>
            <td class="mono muted">{{ $coverage->member_no }}</td>
            <td>
              <x-ui.badge tone="info">{{ rtrim(rtrim((string) $coverage->coverage_percent, '0'), '.') }}%</x-ui.badge>
              @unless($coverage->isValid())<x-ui.badge tone="danger">Expired</x-ui.badge>@endunless
            </td>
            <td class="muted tb-nowrap">{{ $coverage->valid_to?->format('d M Y') ?? '—' }}</td>
            <td class="tb-text-right">
              @can('manage', \App\Models\InsuranceClaim::class)
                <x-ui.icon-button label="Remove coverage with {{ $coverage->provider?->name ?? 'provider' }}" icon="fa-trash" variant="danger"
                                  wire:click="remove({{ $coverage->id }})" wire:confirm="Remove this coverage?" />
              @endcan
            </td>
          </tr>
        @empty
          <tr><td colspan="5"><x-ui.empty icon="fa-shield-heart" noun="insurance on file" /></td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  {{-- ── Slide-over: add coverage ────────────────────────────── --}}
  <x-ui.modal size="md" show="showAdd" title="Add insurance coverage">
    @if($showAdd)
      <form wire:submit="add" style="display:contents;">
        <div class="tb-modal-body">
          <x-ui.field label="Provider" for="ins-provider" name="insurance_provider_id" required>
            <select id="ins-provider" wire:model="insurance_provider_id" class="tb-select" required>
              <option value="">— select —</option>
              @foreach($this->providers as $provider)
                <option value="{{ $provider->id }}">{{ $provider->name }}</option>
              @endforeach
            </select>
          </x-ui.field>
          <x-ui.field label="Member no." for="ins-member" name="member_no" required>
            <input id="ins-member" type="text" wire:model="member_no" class="tb-input" maxlength="64" required>
          </x-ui.field>
          <x-ui.field label="Coverage %" for="ins-coverage" name="coverage_percent" required>
            <input id="ins-coverage" type="number" step="0.01" min="0" max="100" wire:model="coverage_percent" class="tb-input" required>
            <x-ui.suggestions set="coverage_percent" :current="$coverage_percent"
                              :options="['50' => '50%', '70' => '70%', '80' => '80%', '90' => '90%', '100' => 'Full cover']" />
          </x-ui.field>
          <x-ui.field label="Valid from" for="ins-from" name="valid_from">
            <input id="ins-from" type="date" wire:model="valid_from" class="tb-input">
          </x-ui.field>
          <x-ui.field label="Valid to" for="ins-to" name="valid_to">
            <input id="ins-to" type="date" wire:model="valid_to" class="tb-input">
          </x-ui.field>
          <div class="tb-form-group">
            <label class="tb-check-group"><input type="checkbox" wire:model="is_active"> Active</label>
          </div>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="add">
            <span wire:loading.remove wire:target="add"><i class="fas fa-check" aria-hidden="true"></i> Add coverage</span>
            <span wire:loading wire:target="add"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
