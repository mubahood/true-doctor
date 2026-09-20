<div>
  <h1 class="sr-only">Plans</h1>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Search plan…" aria-label="Search plans">
    </div>
    <div wire:loading.flex wire:target="search,gotoPage,previousPage,nextPage,perPage" class="muted tb-small tb-flex" style="gap:6px;"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>

    <div class="tb-toolbar-actions">
      @can('create', \App\Models\Plan::class)<button type="button" wire:click="create" class="btn-tb btn-tb-primary"><i class="fas fa-plus" aria-hidden="true"></i> New plan</button>@endcan
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="search,gotoPage,previousPage,nextPage,perPage"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th>Name</th><th class="tb-text-right">Price (USD)</th><th>Billing cycle</th><th>Limits</th><th>Features</th><th>Subscriptions</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
      @forelse($rows as $plan)
        <tr wire:key="plan-{{ $plan->id }}">
          <td class="tb-fw-500">{{ $plan->name }} @if($plan->is_featured)<x-ui.badge tone="warn">Popular</x-ui.badge>@endif
            @if($plan->description)<div class="muted tb-xs">{{ $plan->description }}</div>@endif
          </td>
          <td class="mono tb-text-right">${{ number_format((float) $plan->price, 2) }}</td>
          <td>{{ $plan->billing_cycle->label() }}</td>
          <td class="muted tb-xs">{{ $plan->limit('max_staff') ?? '∞' }} staff · {{ $plan->limit('max_patients') ?? '∞' }} patients · {{ $plan->limit('max_beds') ?? '∞' }} beds</td>
          <td class="muted tb-xs">{{ count((array) $plan->features) }}</td>
          <td class="muted">{{ $plan->subscriptions_count }}</td>
          <td><x-ui.badge :tone="$plan->is_active ? 'active' : 'neutral'">{{ $plan->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></td>
          <td class="tb-text-right tb-nowrap">
            @can('update', $plan)<x-ui.icon-button label="Edit {{ $plan->name }}" icon="fa-pen" wire:click="edit({{ $plan->id }})" />@endcan
          </td>
        </tr>
      @empty
        <tr><td colspan="8"><x-ui.empty icon="fa-layer-group" noun="plans" :filtered="$search !== ''" wire:click="$set('search', '')" /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  {{ $rows->links(data: $this->paginationData()) }}

  {{-- ── Slide-over: create / edit ─────────────────────────────── --}}
  <x-ui.modal show="showForm" :title="$editingId ? 'Edit plan' : 'New plan'">
    @if($showForm)
    <form wire:submit="save" style="display:contents;">
      <div class="tb-modal-body">
        <x-ui.field label="Name" for="plan-name" name="name" required>
          <input id="plan-name" type="text" wire:model="name" class="tb-input" required>
        </x-ui.field>
        <x-ui.field label="Tagline" for="plan-desc" name="description" hint="One line shown under the plan name, e.g. &quot;For growing clinics&quot;.">
          <input id="plan-desc" type="text" wire:model="description" class="tb-input" maxlength="160">
        </x-ui.field>
        <div class="tb-form-grid">
          <x-ui.field label="Slug" for="plan-slug" name="slug" hint="Auto-generated if left blank.">
            <input id="plan-slug" type="text" wire:model="slug" class="tb-input">
          </x-ui.field>
          <x-ui.field label="Price (USD)" for="plan-price" name="price" required hint="Charged to the hospital in UGX at checkout, at the platform's fixed rate.">
            <input id="plan-price" type="number" step="0.01" min="0" wire:model="price" class="tb-input" required>
          </x-ui.field>
          <x-ui.field label="Billing cycle" for="plan-cycle" name="billing_cycle" required>
            <select id="plan-cycle" wire:model="billing_cycle" class="tb-select">
              @foreach($this->cycles as $val => $lbl)<option value="{{ $val }}">{{ $lbl }}</option>@endforeach
            </select>
          </x-ui.field>
          <x-ui.field label="Max staff" for="plan-staff" name="max_staff" hint="Blank = unlimited.">
            <input id="plan-staff" type="number" wire:model="max_staff" class="tb-input" min="1">
          </x-ui.field>
          <x-ui.field label="Max patients" for="plan-patients" name="max_patients" hint="Blank = unlimited.">
            <input id="plan-patients" type="number" wire:model="max_patients" class="tb-input" min="1">
          </x-ui.field>
          <x-ui.field label="Max beds" for="plan-beds" name="max_beds" hint="Blank = unlimited.">
            <input id="plan-beds" type="number" wire:model="max_beds" class="tb-input" min="1">
          </x-ui.field>
        </div>

        <div class="tb-form-group">
          <span class="tb-label">Features</span>
          @foreach($features as $i => $feature)
            <div class="tb-flex tb-mt-2" wire:key="feature-{{ $i }}">
              <input type="text" wire:model="features.{{ $i }}" class="tb-input tb-grow" placeholder="e.g. Priority support" maxlength="80">
              <x-ui.icon-button label="Remove this feature" icon="fa-xmark" wire:click="removeFeature({{ $i }})" />
            </div>
            @error('features.'.$i)<div class="tb-field-error">{{ $message }}</div>@enderror
          @endforeach
          <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm tb-mt-3" wire:click="addFeature">
            <i class="fas fa-plus" aria-hidden="true"></i> Add a feature
          </button>
        </div>

        <div class="tb-form-group">
          <label class="tb-check-group"><input type="checkbox" wire:model="is_active"> Active</label>
          <label class="tb-check-group"><input type="checkbox" wire:model="is_featured"> Show a "Most popular" ribbon on this plan</label>
        </div>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
          <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> {{ $editingId ? 'Save changes' : 'Create plan' }}</span>
          <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
        </button>
      </div>
    </form>
    @endif
  </x-ui.modal>
</div>
