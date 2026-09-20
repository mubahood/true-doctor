<livewire:dashboard.section widget="nurse.stats" lazy />

<div class="dash-grid cols-2">
  @can('visits.vitals')<livewire:dashboard.section widget="nurse.not-started" lazy />@endcan
  @can('prescriptions.administer')<livewire:dashboard.section widget="nurse.doses" lazy />@endcan
</div>

<div class="dash-section">
  <div class="dash-section-title"><i class="fas fa-bolt" aria-hidden="true"></i> Quick actions</div>
  <div class="dash-actions">
    @can('ipd.view')<x-dash.quick :href="route('admin.admissions.board')" icon="fa-hospital-user" label="Occupancy board" />@endcan
    @can('visits.view')<x-dash.quick :href="route('admin.visits.index')" icon="fa-notes-medical" label="Visits" />@endcan
    @can('patients.view')<x-dash.quick :href="route('admin.patients.index')" icon="fa-user-injured" label="Patients" />@endcan
  </div>
</div>
