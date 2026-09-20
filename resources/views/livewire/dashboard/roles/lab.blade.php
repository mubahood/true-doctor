<livewire:dashboard.section widget="lab.stats" lazy />

<div class="dash-grid cols-2">
  <livewire:dashboard.section widget="lab.worklist" lazy />
  <livewire:dashboard.section widget="lab.stages" lazy />
</div>

<div class="dash-section">
  <div class="dash-section-title"><i class="fas fa-bolt" aria-hidden="true"></i> Quick actions</div>
  <div class="dash-actions">
    <x-dash.quick :href="route('admin.lab-orders.index')" icon="fa-flask" label="Lab worklist" />
    <x-dash.quick :href="route('admin.lab-tests.index')" icon="fa-vials" label="Test catalogue" />
  </div>
</div>
