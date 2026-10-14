<div>
  <h1 class="sr-only">Standalone licences</h1>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Buyer, email, address or reference…" aria-label="Search licences">
    </div>
    <div class="tb-toolbar-actions">
      <button type="button" class="btn-tb btn-tb-primary" wire:click="openIssue"><i class="fas fa-plus" aria-hidden="true"></i> Issue a licence</button>
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="search,gotoPage,previousPage,nextPage,perPage"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th>Buyer</th><th>Address</th><th>Product</th><th>Source</th><th>Updates until</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
      @forelse($licences as $licence)
        <tr wire:key="licence-{{ $licence->id }}" x-data="{ editing: false, domain: @js($licence->domain()) }">
          <td class="tb-fw-500">{{ $licence->customer_name }}<div class="muted tb-xs">{{ $licence->customer_email }}</div></td>
          <td class="mono tb-xs">
            <span x-show="! editing">{{ $licence->domain() }}</span>
            <span x-show="editing" x-cloak class="tb-flex" style="gap:4px;">
              <input type="text" x-model="domain" class="tb-input" aria-label="New address for {{ $licence->customer_name }}">
              <button type="button" class="btn-tb btn-tb-sm btn-tb-primary" x-on:click="$wire.changeDomain('{{ $licence->id }}', domain); editing = false">Save</button>
            </span>
          </td>
          <td class="tb-small">{{ $licence->product?->name ?? '—' }}</td>
          <td class="tb-small">{{ ucfirst($licence->source) }}@if($licence->reference)<div class="muted tb-xs mono">{{ \Illuminate\Support\Str::limit($licence->reference, 22) }}</div>@endif</td>
          <td class="tb-small">{{ $licence->updates_until->format('d M Y') }}</td>
          <td><x-ui.badge :tone="$licence->status === 'active' ? 'active' : 'danger'">{{ ucfirst($licence->status) }}</x-ui.badge></td>
          <td class="tb-text-right tb-nowrap">
            <x-ui.icon-button label="Email {{ $licence->customer_name }} a download link" icon="fa-envelope" wire:click="sendLink('{{ $licence->id }}')" />
            <x-ui.icon-button label="Change the address" icon="fa-globe" x-on:click="editing = ! editing" />
            <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm" wire:click="renew('{{ $licence->id }}')" wire:confirm="Add {{ $licence->product?->updates_months ?? 12 }} months of updates to {{ $licence->customer_name }}'s licence?">Extend</button>
            @if($licence->status === 'active')
              <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm" wire:click="revoke('{{ $licence->id }}')" wire:confirm="Stop downloads for {{ $licence->customer_name }}? Their installed system keeps working.">Revoke</button>
            @else
              <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm" wire:click="reinstate('{{ $licence->id }}')">Reinstate</button>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="7"><x-ui.empty icon="fa-certificate" noun="licences" :filtered="$search !== ''" wire:click="$set('search', '')" /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  {{ $licences->links(data: $this->paginationData()) }}

  <x-ui.modal show="showIssue" size="md" title="Issue a licence">
    @if($showIssue)
      <form wire:submit="issue" style="display:contents;">
        <div class="tb-modal-body">
          <p class="muted tb-small">For a sale made outside checkout. The buyer is emailed their download link straight away.</p>
          <x-ui.field label="Product" for="licence-product" name="productId" required>
            <select id="licence-product" class="tb-select" wire:model="productId" required>
              <option value="">Choose…</option>
              @foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }} · {{ $product->updates_months }} months of updates</option>@endforeach
            </select>
          </x-ui.field>
          <div class="tb-form-grid">
            <x-ui.field label="Buyer name" for="licence-name" name="customerName" required><input id="licence-name" class="tb-input" wire:model="customerName" required></x-ui.field>
            <x-ui.field label="Buyer email" for="licence-email" name="customerEmail" required><input id="licence-email" class="tb-input" type="email" wire:model="customerEmail" required></x-ui.field>
            <x-ui.field label="Address they install on" for="licence-domain" name="domain" required><input id="licence-domain" class="tb-input" wire:model="domain" placeholder="hms.example.com" required></x-ui.field>
            <x-ui.field label="Payment reference" for="licence-reference" name="reference" required hint="Bank reference, PO or contract number."><input id="licence-reference" class="tb-input" wire:model="reference" required></x-ui.field>
          </div>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="issue"><i class="fas fa-certificate" aria-hidden="true"></i> Issue and email</button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
