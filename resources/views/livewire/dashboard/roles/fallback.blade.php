{{-- Permission-driven: shows whatever the role is allowed to see. --}}
<livewire:dashboard.section widget="fallback.stats" lazy />

<div class="dash-section">
  <div class="dash-section-title"><i class="fas fa-bolt" aria-hidden="true"></i> Quick actions</div>
  <div class="dash-actions">
    @can('patients.create')<x-dash.quick :href="route('admin.patients.create')" icon="fa-user-plus" label="Register patient" />@endcan
    @can('patients.view')<x-dash.quick :href="route('admin.patients.index')" icon="fa-user-injured" label="Patients" />@endcan
    @can('appointments.view')<x-dash.quick :href="route('admin.appointments.index')" icon="fa-calendar-check" label="Appointments" />@endcan
    @can('visits.view')<x-dash.quick :href="route('admin.visits.index')" icon="fa-notes-medical" label="Visits" />@endcan
    @can('billing.view')<x-dash.quick :href="route('admin.invoices.index')" icon="fa-file-invoice" label="Invoices" />@endcan
    @can('pharmacy.view')<x-dash.quick :href="route('admin.stock.index')" icon="fa-boxes-stacked" label="Stock" />@endcan
    @can('lab.view')<x-dash.quick :href="route('admin.lab-orders.index')" icon="fa-flask" label="Lab" />@endcan
    @can('radiology.view')<x-dash.quick :href="route('admin.radiology-orders.index')" icon="fa-x-ray" label="Radiology" />@endcan
  </div>
</div>
