<livewire:dashboard.section widget="receptionist.stats" lazy />

<div class="dash-grid cols-2">
  @can('appointments.view')<livewire:dashboard.section widget="receptionist.diary" lazy />@endcan
  @can('billing.view')<livewire:dashboard.section widget="receptionist.awaiting-payment" lazy />@endcan
</div>

<div class="dash-section">
  <div class="dash-section-title"><i class="fas fa-bolt" aria-hidden="true"></i> Quick actions</div>
  <div class="dash-actions">
    @can('patients.create')<x-dash.quick :href="route('admin.patients.create')" icon="fa-user-plus" label="Register patient" />@endcan
    @can('appointments.manage')<x-dash.quick :href="route('admin.appointments.index')" icon="fa-calendar-plus" label="Book appointment" />@endcan
    @can('visits.create')<x-dash.quick :href="route('admin.visits.index')" icon="fa-notes-medical" label="Visits" />@endcan
    @can('billing.view')<x-dash.quick :href="route('admin.invoices.index')" icon="fa-file-invoice" label="Invoices" />@endcan
  </div>
</div>
