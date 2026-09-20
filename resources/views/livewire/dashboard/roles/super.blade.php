<livewire:dashboard.section widget="super.stats" lazy />

<livewire:dashboard.section widget="super.charts" lazy />

<livewire:dashboard.section widget="super.per-plan" lazy />

<div class="dash-section">
  <div class="dash-section-title"><i class="fas fa-bolt" aria-hidden="true"></i> Quick actions</div>
  <div class="dash-actions">
    <x-dash.quick :href="route('super.hospitals.index')" icon="fa-hospital" label="Hospitals" />
    <x-dash.quick :href="route('super.plans.index')" icon="fa-layer-group" label="Plans" />
    <x-dash.quick :href="route('super.subscriptions.index')" icon="fa-file-invoice-dollar" label="Subscriptions" />
    <x-dash.quick :href="route('admin.settings.index')" icon="fa-sliders" label="Site settings" />
  </div>
</div>
