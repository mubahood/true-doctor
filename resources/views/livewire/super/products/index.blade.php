<div>
  <x-ui.page-header title="Standalone products">
    <x-slot:explain>What the self-hosted edition sells for, shown on the public "Own it" page. Changes apply at once; an order already made keeps the price and update period it was made at.</x-slot:explain>
  </x-ui.page-header>

  <div class="tb-card">
    <div class="tb-card-header"><span class="tb-card-title">{{ $editingId ? 'Edit '.$name : 'New product' }}</span></div>
    <div class="tb-card-body">
      <form wire:submit="save">
        <div class="tb-form-grid">
          <x-ui.field label="Name" for="product-name" name="name" required><input id="product-name" class="tb-input" wire:model="name" required placeholder="e.g. Clinic"></x-ui.field>
          <x-ui.field label="Price, once (UGX)" for="product-price" name="price" required><input id="product-price" class="tb-input" type="number" min="1" step="1" wire:model="price" required></x-ui.field>
          <x-ui.field label="Months of updates included" for="product-months" name="updatesMonths" required><input id="product-months" class="tb-input" type="number" min="1" max="60" wire:model="updatesMonths" required></x-ui.field>
          <x-ui.field label="Renewal price (UGX)" for="product-renewal" name="renewalPrice" hint="For another period of updates, bought from the buyer's downloads page. Blank: renew by contacting you."><input id="product-renewal" class="tb-input" type="number" min="1" step="1" wire:model="renewalPrice"></x-ui.field>
          <x-ui.field label="Staff accounts" for="product-staff" name="maxStaff" hint="Blank means unlimited. Patients and beds are never limited."><input id="product-staff" class="tb-input" type="number" min="1" wire:model="maxStaff"></x-ui.field>
          <x-ui.field label="Internal handle" for="product-slug" name="slug" hint="Made from the name if left blank."><input id="product-slug" class="tb-input mono" wire:model="slug"></x-ui.field>
        </div>
        <x-ui.field label="Description" for="product-description" name="description" hint="One line under the price on the public page."><textarea id="product-description" class="tb-input" wire:model="description" rows="2"></textarea></x-ui.field>
        <label class="tb-check-group"><input type="checkbox" wire:model="isActive"> On sale</label>
        <div class="wz-actions">
          <button class="btn-tb btn-tb-primary" type="submit" wire:loading.attr="disabled" wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> Save product</button>
          @if($editingId)<button class="btn-tb btn-tb-ghost" type="button" wire:click="resetForm">Cancel</button>@endif
        </div>
      </form>
    </div>
  </div>

  <div class="tb-card"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th>Product</th><th class="tb-text-right">Price</th><th class="tb-text-right">Renewal</th><th>Updates</th><th>Staff</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
      @forelse($products as $product)
        <tr wire:key="product-{{ $product->id }}">
          <td class="tb-fw-500">{{ $product->name }}@if($product->description)<div class="muted tb-xs">{{ $product->description }}</div>@endif</td>
          <td class="mono tb-text-right">{{ \App\Support\PlatformCurrency::format($product->price_ugx) }}</td>
          <td class="mono tb-text-right">{{ $product->renewal_price_ugx ? \App\Support\PlatformCurrency::format($product->renewal_price_ugx) : '—' }}</td>
          <td>{{ $product->updates_months }} months</td>
          <td>{{ $product->limits['max_staff'] ?? 'Unlimited' }}</td>
          <td><x-ui.badge :tone="$product->is_active ? 'active' : 'neutral'">{{ $product->is_active ? 'On sale' : 'Hidden' }}</x-ui.badge></td>
          <td class="tb-text-right"><x-ui.icon-button label="Edit {{ $product->name }}" icon="fa-pen" wire:click="edit({{ $product->id }})" /></td>
        </tr>
      @empty
        <tr><td colspan="7"><x-ui.empty icon="fa-tag" noun="products" message="No product yet. Add one above, then tick On sale." /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
</div>
