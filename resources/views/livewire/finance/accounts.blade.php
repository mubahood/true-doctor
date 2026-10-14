<div>
  <h1 class="sr-only">Chart of accounts</h1>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.300ms="search" class="tb-input" placeholder="Code or name…" aria-label="Search accounts">
    </div>
    <select wire:model.live="filterType" class="tb-select" aria-label="Kind of account">
      <option value="">Every kind</option>
      @foreach($types as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
    </select>
    <label class="tb-flex tb-small muted" style="gap:6px;"><input type="checkbox" wire:model.live="showInactive"> Show switched-off</label>
    <div class="tb-toolbar-actions">
      @can('finance.manage')
        <button type="button" class="btn-tb" wire:click="create('asset')"><i class="fas fa-building-columns" aria-hidden="true"></i> New book</button>
        <button type="button" class="btn-tb btn-tb-primary" wire:click="create('expense')"><i class="fas fa-plus" aria-hidden="true"></i> New account</button>
      @endcan
    </div>
  </div>

  @forelse($groups as $g)
    @php($groupTotal = $g['accounts']->reduce(fn ($c, $a) => bcadd($c, $this->balances[$a->id] ?? '0.00', 2), '0.00'))
    <div class="tb-card fn-coa" wire:key="coa-{{ $g['type']->value }}">
      <div class="tb-card-header">
        <span class="tb-card-title">{{ $g['type']->plural() }}</span>
        <span class="muted tb-small">{{ $g['type']->isTemporary() ? 'This year so far' : 'Today' }} · <b class="mono">{{ \App\Support\HospitalSettings::money($groupTotal) }}</b></span>
      </div>
      <div class="tb-table-wrap"><table class="tb-table">
        <tbody>
          @foreach($g['accounts'] as $a)
            <tr wire:key="acc-{{ $a->id }}" @class(['is-muted' => ! $a->is_active])>
              <td class="mono tb-small muted" style="width:80px;">{{ $a->code }}</td>
              <td>
                <x-ui.link :href="route('admin.finance.accounts.show', $a)" class="tb-fw-500">{{ $a->name }}</x-ui.link>
                @if($a->is_book)<x-ui.badge tone="info">Book</x-ui.badge>@endif
                @if($a->isSystem())<span class="muted tb-xs" title="The system posts here automatically"><i class="fas fa-gears" aria-hidden="true"></i></span>@endif
                @unless($a->is_active)<x-ui.badge tone="neutral">Off</x-ui.badge>@endunless
                @if($a->description)<div class="muted tb-xs">{{ $a->description }}</div>@endif
              </td>
              <td class="tb-text-right"><span @class(['mono', 'fn-neg' => (float) ($this->balances[$a->id] ?? 0) < 0])>{{ \App\Support\HospitalSettings::money($this->balances[$a->id] ?? '0.00') }}</span></td>
              <td class="tb-text-right tb-nowrap">
                <x-ui.icon-button label="Ledger for {{ $a->name }}" icon="fa-book-open" :href="route('admin.finance.accounts.show', $a)" />
                @can('finance.manage')
                  <x-ui.icon-button label="Edit {{ $a->name }}" icon="fa-pen" wire:click="edit({{ $a->id }})" />
                  @unless($a->isSystem())
                    <x-ui.icon-button :label="($a->is_active ? 'Switch off ' : 'Switch on ').$a->name" :icon="$a->is_active ? 'fa-toggle-on' : 'fa-toggle-off'" wire:click="toggleActive({{ $a->id }})" />
                    @if(($this->usage[$a->id] ?? 0) === 0)
                      <x-ui.icon-button label="Delete {{ $a->name }}" icon="fa-trash" variant="danger" wire:click="delete({{ $a->id }})" wire:confirm="Delete {{ $a->name }}? It has no entries." />
                    @endif
                  @endunless
                @endcan
              </td>
            </tr>
          @endforeach
        </tbody>
      </table></div>
    </div>
  @empty
    <div class="tb-card"><x-ui.empty icon="fa-list-ol" noun="accounts" :filtered="true" wire:click="$set('search', '')" /></div>
  @endforelse

  <x-ui.modal show="showForm" size="md" :title="$editingId ? 'Edit account' : 'New account'">
    @if($showForm)
      <form wire:submit="save" style="display:contents;">
        <div class="tb-modal-body">
          <div class="tb-form-grid">
            <x-ui.field label="Kind" for="ac-type" name="type" required>
              <select id="ac-type" wire:model.live="type" class="tb-select" required>
                @foreach($types as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
              </select>
            </x-ui.field>
            <x-ui.field label="Code" for="ac-code" name="code" required hint="Its place in the chart">
              <input id="ac-code" type="text" wire:model="code" class="tb-input mono" maxlength="12" required>
            </x-ui.field>
          </div>
          <x-ui.field label="Name" for="ac-name" name="name" required>
            <input id="ac-name" type="text" wire:model="name" class="tb-input" maxlength="120" required placeholder="e.g. Generator fuel">
          </x-ui.field>
          <x-ui.field label="Description" for="ac-desc" name="description">
            <input id="ac-desc" type="text" wire:model="description" class="tb-input" maxlength="255">
          </x-ui.field>
          @if($type === 'asset')
            <label class="tb-flex tb-small tb-mt-2" style="gap:8px;"><input type="checkbox" wire:model="is_book">
              <span><b>Money is kept here</b> — a cash drawer, bank account or mobile-money wallet. It will be offered wherever money is received or paid.</span></label>
          @endif
          <label class="tb-flex tb-small tb-mt-2" style="gap:8px;"><input type="checkbox" wire:model="is_active"> In use</label>
          @error('is_active')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> Save</span>
            <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
