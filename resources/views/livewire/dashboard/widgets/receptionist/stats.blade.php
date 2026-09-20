@php $money = app(\App\Support\HospitalSettings::class); @endphp
<div class="tb-stats-grid">
  @can('patients.view')
    <x-dash.stat :value="number_format($patientsNewToday ?? 0)" label="New patients today" icon="fa-user-plus"
      :href="route('admin.patients.index')" />
  @endcan
  @can('appointments.view')
    <x-dash.stat :value="number_format($appointmentsToday ?? 0)" label="Appointments today" icon="fa-calendar-check"
      :href="route('admin.appointments.queue')" />
    <x-dash.stat :value="number_format($awaitingStart ?? 0)" label="Checked-in waiting" icon="fa-users-line" tone="warn"
      :href="route('admin.appointments.queue')" />
  @endcan
  @can('billing.view')
    <x-dash.stat :value="$money->format($revenueToday ?? '0')" label="Payments today" icon="fa-coins" tone="ok"
      :sub="($paymentsToday ?? 0).' received'" :href="route('admin.invoices.index')" />
  @endcan
</div>
