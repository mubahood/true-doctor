<livewire:dashboard.section widget="doctor.stats" lazy />

<div class="dash-grid cols-2">
  @can('visits.view')<livewire:dashboard.section widget="doctor.visits" lazy />@endcan
  @can('appointments.view')<livewire:dashboard.section widget="doctor.diary" lazy />@endcan
</div>

<div class="dash-section">
  <div class="dash-section-title"><i class="fas fa-bolt" aria-hidden="true"></i> Quick actions</div>
  <div class="dash-actions">
    @can('visits.create')<x-dash.quick :href="route('admin.visits.index')" icon="fa-notes-medical" label="Visits" />@endcan
    @can('appointments.view')<x-dash.quick :href="route('admin.appointments.index')" icon="fa-calendar-check" label="My appointments" />@endcan
    @can('patients.view')<x-dash.quick :href="route('admin.patients.index')" icon="fa-user-injured" label="Patients" />@endcan
    @can('lab.order')<x-dash.quick :href="route('admin.lab-orders.index')" icon="fa-flask" label="Lab orders" />@endcan
    @can('radiology.order')<x-dash.quick :href="route('admin.radiology-orders.index')" icon="fa-x-ray" label="Radiology" />@endcan
  </div>
</div>
