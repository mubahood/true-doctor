<div>
  <h1 class="sr-only">Wards</h1>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Search ward…" aria-label="Search wards">
    </div>
    <div wire:loading.flex wire:target="search,gotoPage,previousPage,nextPage,perPage" class="muted tb-small tb-flex" style="gap:6px;"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>

    <div class="tb-toolbar-actions">
      @can('create', \App\Models\Ward::class)
        <button type="button" wire:click="openSamples" class="btn-tb"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i> Import samples</button>
        <button type="button" wire:click="create" class="btn-tb btn-tb-primary"><i class="fas fa-plus" aria-hidden="true"></i> New ward</button>
      @endcan
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="search,gotoPage,previousPage,nextPage,perPage"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th class="tb-serial">#</th><th>Ward</th><th>Beds</th><th>Per night</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
      @forelse($rows as $r)
        <tr wire:key="wards-{{ $r->id }}">
          <x-ui.serial :rows="$rows" :loop="$loop" />
          {{-- Opens the ward over the list, like everywhere else. --}}
          <td class="tb-fw-500">
            <button type="button" class="tb-rowbtn" wire:click="peek({{ $r->id }})"
                    title="See this ward">{{ $r->name }}</button>
          </td>
          <td class="muted">{{ $r->beds_count }}</td>
          <td class="muted tb-nowrap">{{ \App\Support\HospitalSettings::money($r->default_daily_charge) }}</td>
          <td><x-ui.badge :tone="$r->is_active ? 'success' : 'danger'">{{ $r->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></td>
          <td class="tb-text-right tb-nowrap">
            <x-ui.icon-button label="See {{ $r->name }}" icon="fa-eye" wire:click="peek({{ $r->id }})" />
            @can('update', $r)<x-ui.icon-button label="Edit {{ $r->name }}" icon="fa-pen" wire:click="edit({{ $r->id }})" />@endcan
            @can('delete', $r)<x-ui.icon-button label="Delete {{ $r->name }}" icon="fa-trash" wire:click="delete({{ $r->id }})" wire:confirm="Delete this ward?" />@endcan
          </td>
        </tr>
      @empty
        <tr><td colspan="6"><x-ui.empty icon="fa-hospital" noun="wards" :filtered="$search !== ''" wire:click="$set('search', '')" /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  {{ $rows->links(data: $this->paginationData()) }}

  {{-- ── Slide-over: create / edit ─────────────────────────────── --}}
  <x-ui.modal show="showForm" :title="$editingId ? 'Edit ward' : 'New ward'">
    @if($showForm)
    <form wire:submit="save" style="display:contents;">
      <div class="tb-modal-body">
        <x-ui.field label="Name" for="ward-name" name="name" required>
          <input id="ward-name" type="text" wire:model="name" class="tb-input" required>
        </x-ui.field>
        <x-ui.field label="Description" for="ward-desc" name="description">
          <textarea id="ward-desc" wire:model="description" class="tb-textarea" rows="2"></textarea>
        </x-ui.field>

        {{-- ── What a night here costs ────────────────────────────────
             The ward's STANDARD rate. What is actually billed lives on the
             bed, because a side room and a bay are not worth the same; this
             is the figure a new bed starts from, and the one to apply when
             the whole ward is repriced. --}}
        <x-ui.field label="Nightly charge per bed" for="ward-charge" name="default_daily_charge" required
                    hint="The standard rate for this ward. Each bed keeps its own charge — a side room can cost more.">
          <input id="ward-charge" type="number" step="0.01" min="0" max="99999999.99"
                 wire:model="default_daily_charge" class="tb-input" inputmode="decimal" required>
        </x-ui.field>

        @if($editingId)
          @php($impact = $this->repriceImpact)
          <div class="tb-form-group">
            <label class="tb-check-group">
              <input type="checkbox" wire:model.live="apply_to_beds">
              Also set every bed in this ward to that amount
            </label>

            @if($impact['beds'] === 0)
              <div class="tb-field-hint">This ward has no beds yet, so there is nothing to apply it to.</div>
            @else
              <div class="tb-field-hint">
                Would change {{ $impact['beds'] }} {{ Str::plural('bed', $impact['beds']) }}.
              </div>
            @endif

            {{-- The consequence nobody would guess, shown only when it is
                 real. A discharge reads the bed's rate and multiplies it by
                 the WHOLE stay, so repricing an occupied bed reprices nights
                 that were already spent at the old rate. --}}
            @if($apply_to_beds && $impact['occupied'] > 0)
              <div class="tb-field-error" role="alert">
                <strong>{{ $impact['occupied'] }}
                  {{ Str::plural('bed', $impact['occupied']) }} {{ $impact['occupied'] === 1 ? 'is' : 'are' }}
                  occupied right now.</strong>
                Those stays are billed when the patient is discharged, at whatever the bed costs
                then — so every night already spent in them will be charged at this new rate, not
                the one in force at the time. Change the beds individually if that is not what you
                mean.
              </div>
            @endif
          </div>
        @endif

        <div class="tb-form-group">
          <label class="tb-check-group"><input type="checkbox" wire:model="is_active"> Active</label>
        </div>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
          <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> {{ $editingId ? 'Save changes' : 'Create ward' }}</span>
          <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
        </button>
      </div>
    </form>
    @endif
  </x-ui.modal>

  <x-ui.sample-import :samples="$samples" :open="$showSamples" title="Import starter wards" noun="wards" import-label="Import wards"
    :columns="[]" />

  @include('livewire.partials.ward-peek')
</div>
