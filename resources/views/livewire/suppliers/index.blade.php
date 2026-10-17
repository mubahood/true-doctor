<div>
  <h1 class="sr-only">Suppliers</h1>

  @php($targets = 'search,status,gotoPage,previousPage,nextPage,perPage,sortBy,clearFilters')

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Search supplier, contact or phone…" aria-label="Search suppliers">
    </div>

    <select wire:model.live="status" class="tb-select" aria-label="Filter by status">
      <option value="">Active and inactive</option>
      <option value="active">Active only</option>
      <option value="inactive">Inactive only</option>
    </select>

    @if($this->hasFilters())
      <button type="button" class="tb-chip" wire:click="clearFilters"><i class="fas fa-xmark" aria-hidden="true"></i> Clear</button>
    @endif

    <div wire:loading.flex wire:target="{{ $targets }}" class="muted tb-small tb-flex"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>

    <div class="tb-toolbar-actions">
      <x-ui.link :href="route('admin.purchase-orders.index')" class="btn-tb">
        <i class="fas fa-file-invoice" aria-hidden="true"></i> Purchase orders
      </x-ui.link>
      @can('create', \App\Models\Supplier::class)
        <button type="button" wire:click="create" class="btn-tb btn-tb-primary"><i class="fas fa-plus" aria-hidden="true"></i> New supplier</button>
      @endcan
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="{{ $targets }}"><div class="tb-table-wrap">
    <table class="tb-table">
      <caption class="sr-only">Every supplier the store buys from</caption>
      <thead><tr>
        <th class="tb-serial">#</th>
        <x-ui.th-sort field="name" :sort-field="$sortField" :sort-dir="$sortDir">Supplier</x-ui.th-sort>
        <th>Contact</th>
        <th>Phone</th>
        <th>Terms</th>
        <x-ui.th-sort field="orders_count" :sort-field="$sortField" :sort-dir="$sortDir" align="right">Orders</x-ui.th-sort>
        <x-ui.th-sort field="is_active" :sort-field="$sortField" :sort-dir="$sortDir">Status</x-ui.th-sort>
        <th><span class="sr-only">Actions</span></th>
      </tr></thead>
      <tbody>
        @forelse($rows as $r)
          <tr wire:key="supplier-{{ $r->id }}">
            <x-ui.serial :rows="$rows" :loop="$loop" />
            <td class="tb-fw-500">
              <button type="button" class="tb-rowbtn" wire:click="peek({{ $r->id }})" title="See this supplier">{{ $r->name }}</button>
              @if($r->tin)<span class="tb-row-sub">TIN {{ $r->tin }}</span>@endif
            </td>
            <td class="muted">{{ $r->contact_person ?? '—' }}</td>
            <td class="muted mono">{{ $r->phone ?? '—' }}</td>
            <td class="muted">{{ $r->payment_terms_days !== null ? $r->payment_terms_days.' days' : '—' }}</td>
            <td class="tb-text-right muted">{{ $r->orders_count }}</td>
            <td><x-ui.badge :tone="$r->is_active ? 'success' : 'danger'">{{ $r->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></td>
            <td class="tb-text-right tb-nowrap">
              <x-ui.icon-button label="See {{ $r->name }}" icon="fa-eye" wire:click="peek({{ $r->id }})" />
              <x-ui.actions-menu label="Actions for {{ $r->name }}">
                <a role="menuitem" wire:navigate href="{{ route('admin.purchase-orders.index', ['supplier' => $r->id]) }}">
                  <i class="fas fa-file-invoice" aria-hidden="true"></i> Orders from this supplier
                </a>
                @can('update', $r)
                  <hr>
                  <button type="button" role="menuitem" wire:click="edit({{ $r->id }})"><i class="fas fa-pen" aria-hidden="true"></i> Edit</button>
                @endcan
                @can('delete', $r)
                  <button type="button" role="menuitem" class="danger" wire:click="delete({{ $r->id }})"
                          wire:confirm="Delete this supplier?"><i class="fas fa-trash" aria-hidden="true"></i> Delete</button>
                @endcan
              </x-ui.actions-menu>
            </td>
          </tr>
        @empty
          <tr><td colspan="8">
            <x-ui.empty icon="fa-truck-field" noun="suppliers" :filtered="$this->hasFilters()" wire:click="clearFilters" />
          </td></tr>
        @endforelse
      </tbody>
    </table>
  </div></div>
  {{ $rows->links(data: $this->paginationData()) }}

  {{-- ── Create / edit ─────────────────────────────────────────── --}}
  <x-ui.modal show="showForm" size="md" :title="$editingId ? 'Edit supplier' : 'New supplier'">
    @if($showForm)
    <form wire:submit="save" class="tb-proc-form">
      <div class="tb-modal-body">
        <x-ui.field label="Name" for="sup-name" name="name" required>
          <input id="sup-name" type="text" wire:model="name" class="tb-input" required>
        </x-ui.field>
        <div class="tb-grid-2">
          <x-ui.field label="Contact person" for="sup-contact" name="contact_person">
            <input id="sup-contact" type="text" wire:model="contact_person" class="tb-input">
          </x-ui.field>
          <x-ui.field label="Phone" for="sup-phone" name="phone">
            <input id="sup-phone" type="tel" wire:model="phone" class="tb-input">
          </x-ui.field>
          <x-ui.field label="Email" for="sup-email" name="email">
            <input id="sup-email" type="email" wire:model="email" class="tb-input">
          </x-ui.field>
          <x-ui.field label="TIN" for="sup-tin" name="tin">
            <input id="sup-tin" type="text" wire:model="tin" class="tb-input">
          </x-ui.field>
        </div>
        <x-ui.field label="Address" for="sup-address" name="address">
          <input id="sup-address" type="text" wire:model="address" class="tb-input">
        </x-ui.field>
        <x-ui.field label="Pays within (days)" for="sup-terms" name="payment_terms_days">
          <input id="sup-terms" type="number" min="0" max="365" wire:model="payment_terms_days" class="tb-input">
        </x-ui.field>
        <x-ui.field label="Notes" for="sup-notes" name="notes">
          <textarea id="sup-notes" wire:model="notes" class="tb-textarea" rows="2"></textarea>
        </x-ui.field>
        <div class="tb-form-group">
          <label class="tb-check-group"><input type="checkbox" wire:model="is_active"> Active</label>
        </div>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
          <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> {{ $editingId ? 'Save changes' : 'Create supplier' }}</span>
          <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
        </button>
      </div>
    </form>
    @endif
  </x-ui.modal>

  {{-- ── Quick view ────────────────────────────────────────────── --}}
  <x-ui.modal show="showPeek" size="lg" autosaves :title="$this->peeked?->name ?? 'Supplier'">
    @if($this->peeked)
      @php($sup = $this->peeked)
      @php($figs = $this->peekedFigures)
      <div class="tb-modal-body">
        <x-ui.peek-head :heading="$sup->name" :sub="collect([$sup->contact_person, $sup->phone, $sup->email])->filter()->implode(' · ')">
          <x-ui.badge :tone="$sup->is_active ? 'success' : 'danger'">{{ $sup->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
        </x-ui.peek-head>

        <x-ui.peek-figs :figures="[
          ['label' => 'Open orders', 'value' => (string) $figs['open'], 'sub' => $figs['orders'].' in all'],
          ['label' => 'Received', 'value' => \App\Support\HospitalSettings::money($figs['received']), 'sub' => 'at cost'],
          ['label' => 'Returned', 'value' => \App\Support\HospitalSettings::money($figs['returned']), 'bad' => bccomp($figs['returned'], '0', 2) > 0],
        ]" />

        @if($sup->address || $sup->tin || $sup->payment_terms_days !== null)
          <div class="tb-peek-sec">Details</div>
          <p class="tb-peek-note">
            {{ collect([$sup->address, $sup->tin ? 'TIN '.$sup->tin : null, $sup->payment_terms_days !== null ? 'Pays within '.$sup->payment_terms_days.' days' : null])->filter()->implode(' · ') }}
          </p>
        @endif
        @if($sup->notes)
          <p class="tb-peek-note">{{ $sup->notes }}</p>
        @endif

        <x-ui.peek-trail title="Latest orders" :rows="$this->peekedOrders" empty="Nothing has been ordered from this supplier yet.">
          @foreach($this->peekedOrders as $po)
            <li wire:key="sup-peek-po-{{ $po->id }}">
              <span class="tb-peek-step">
                <a class="tb-fw-500" wire:navigate href="{{ route('admin.purchase-orders.show', $po) }}">{{ $po->po_no }}</a>
                <x-ui.badge :tone="$po->status->tone()">{{ $po->status->label() }}</x-ui.badge>
              </span>
              <span class="tb-peek-meta">{{ $po->order_date->format('j M Y') }} · {{ \App\Support\HospitalSettings::money($po->total) }}</span>
            </li>
          @endforeach
        </x-ui.peek-trail>
      </div>

      <x-ui.peek-foot :href="route('admin.purchase-orders.index', ['supplier' => $sup->id])" label="All orders" icon="fa-file-invoice">
        @can('update', $sup)
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="editPeeked">
            <i class="fas fa-pen" aria-hidden="true"></i> Edit supplier
          </button>
        @endcan
      </x-ui.peek-foot>
    @endif
  </x-ui.modal>
</div>
