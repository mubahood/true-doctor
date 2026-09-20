<livewire:dashboard.section widget="records.stats" lazy />

<div class="dash-grid cols-2">
  <livewire:dashboard.section widget="records.by-status" lazy />
  <livewire:dashboard.section widget="records.recent" lazy />
</div>

<div class="dash-section">
  <div class="dash-section-title"><i class="fas fa-bolt" aria-hidden="true"></i> Quick actions</div>
  <div class="dash-actions">
    @can('patients.create')<x-dash.quick :href="route('admin.patients.create')" icon="fa-user-plus" label="Register patient" />@endcan
    <x-dash.quick :href="route('admin.patients.index')" icon="fa-user-injured" label="Patients list" />
  </div>
</div>
