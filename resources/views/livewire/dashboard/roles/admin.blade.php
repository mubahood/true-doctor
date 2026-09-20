{{--
  Hospital admin cockpit — each section is a lazy, polled, cached child.

  Read down, it answers the day in order: how much work is in the building,
  what that work is turning into, what is holding it up, and what is left over.
--}}
<livewire:dashboard.section widget="admin.stats" lazy />

{{-- Where everybody is. Full width, because it is a pipeline and a pipeline
     squeezed into half a screen stops reading like one. --}}
@can('visits.view')<livewire:dashboard.section widget="admin.visit-flow" lazy />@endcan

<div class="dash-grid cols-2">
  @can('finance.view')<livewire:dashboard.section widget="admin.money-trend" lazy />@endcan
  @can('ipd.view')<livewire:dashboard.section widget="admin.occupancy" lazy />@endcan
</div>

<div class="dash-grid cols-2">
  @can('visits.view')<livewire:dashboard.section widget="admin.work-queue" lazy />@endcan
  @can('billing.view')<livewire:dashboard.section widget="admin.revenue-by-method" lazy />@endcan
</div>

<div class="dash-grid cols-2">
  @can('patients.card.manage')<livewire:dashboard.section widget="admin.cards" lazy />@endcan
  @can('appointments.view')<livewire:dashboard.section widget="admin.appointments-by-status" lazy />@endcan
</div>

<div class="dash-grid cols-2">
  @can('pharmacy.view')<livewire:dashboard.section widget="admin.low-stock" lazy />@endcan
  <livewire:dashboard.section widget="admin.side-stats" lazy />
</div>

<div class="dash-section">
  <div class="dash-section-title"><i class="fas fa-bolt" aria-hidden="true"></i> Quick actions</div>
  <div class="dash-actions">
    {{-- The visit leads: it is the way into the day's work, and it registers a
         walk-in itself rather than sending anyone to the register first. --}}
    @can('visits.create')<x-dash.quick :href="route('admin.visits.index')" icon="fa-stethoscope" label="Open a visit" />@endcan
    @can('appointments.manage')<x-dash.quick :href="route('admin.appointments.index')" icon="fa-calendar-plus" label="Book appointment" />@endcan
    @can('billing.view')<x-dash.quick :href="route('admin.invoices.index')" icon="fa-file-invoice" label="Invoices" />@endcan
    @can('patients.card.manage')<x-dash.quick :href="route('admin.cards.index')" icon="fa-wallet" label="Cards" />@endcan
    @can('ipd.view')<x-dash.quick :href="route('admin.admissions.board')" icon="fa-hospital-user" label="Occupancy board" />@endcan
    @can('reports.view')<x-dash.quick :href="route('admin.reports.index')" icon="fa-chart-pie" label="Reports" />@endcan
  </div>
</div>
