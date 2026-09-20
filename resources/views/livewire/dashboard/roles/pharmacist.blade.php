<livewire:dashboard.section widget="pharmacist.stats" lazy />

<div class="dash-grid cols-2">
  <livewire:dashboard.section widget="pharmacist.low-stock" lazy />
  <livewire:dashboard.section widget="pharmacist.expiring" lazy />
</div>

<livewire:dashboard.section widget="pharmacist.top-dispensed" lazy />

<div class="dash-section">
  <div class="dash-section-title"><i class="fas fa-bolt" aria-hidden="true"></i> Quick actions</div>
  <div class="dash-actions">
    <x-dash.quick :href="route('admin.stock.index')" icon="fa-boxes-stacked" label="Stock list" />
    @can('pharmacy.manage')<x-dash.quick :href="route('admin.stock.index')" icon="fa-plus" label="Add stock item" />@endcan
    <x-dash.quick :href="route('admin.stock.alerts')" icon="fa-triangle-exclamation" label="Stock alerts" />
    <x-dash.quick :href="route('admin.stock-categories.index')" icon="fa-tags" label="Categories" />
  </div>
</div>
