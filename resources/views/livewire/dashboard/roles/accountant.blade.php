<livewire:dashboard.section widget="accountant.stats" lazy />

<livewire:dashboard.section widget="accountant.revenue-trend" lazy />

<livewire:dashboard.section widget="accountant.breakdowns" lazy />

<div class="dash-grid cols-2">
  <livewire:dashboard.section widget="accountant.outstanding" lazy />
  <livewire:dashboard.section widget="accountant.claims" lazy />
</div>

<div class="dash-section">
  <div class="dash-section-title"><i class="fas fa-bolt" aria-hidden="true"></i> Quick actions</div>
  <div class="dash-actions">
    <x-dash.quick :href="route('admin.invoices.index')" icon="fa-file-invoice" label="Invoices" />
    <x-dash.quick :href="route('admin.insurance-claims.index')" icon="fa-shield-heart" label="Insurance claims" />
    @can('finance.view')<x-dash.quick :href="route('admin.financial-years.index')" icon="fa-calendar-days" label="Financial years" />@endcan
    @can('reports.view')<x-dash.quick :href="route('admin.reports.index')" icon="fa-chart-pie" label="Reports" />@endcan
  </div>
</div>
