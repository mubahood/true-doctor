@php $money = app(\App\Support\HospitalSettings::class); @endphp
{{-- Permission-driven: shows whatever the role is allowed to see. --}}
<div class="tb-stats-grid">
  @can('patients.view')
    <x-dash.stat :value="number_format($patientsTotal ?? 0)" label="Patients" icon="fa-user-injured"
      :sub="($patientsNewToday ?? 0).' new today'" :href="route('admin.patients.index')" />
  @endcan
  @can('appointments.view')
    <x-dash.stat :value="number_format($appointmentsToday ?? 0)" label="Appointments today" icon="fa-calendar-check"
      :href="route('admin.appointments.index')" />
  @endcan
  @can('visits.view')
    <x-dash.stat :value="number_format($visitsToday ?? 0)" label="Visits today" icon="fa-stethoscope"
      :href="route('admin.visits.index')" />
  @endcan
  @can('ipd.view')
    <x-dash.stat :value="number_format($inpatients ?? 0)" label="Inpatients" icon="fa-bed"
      :href="route('admin.admissions.board')" />
  @endcan
  @can('billing.view')
    <x-dash.stat :value="$money->format($revenueToday ?? '0')" label="Revenue today" icon="fa-coins" tone="ok" />
  @endcan
  @can('pharmacy.view')
    <x-dash.stat :value="number_format($lowStock ?? 0)" label="Low stock" icon="fa-triangle-exclamation"
      :tone="($lowStock ?? 0) ? 'warn' : ''" :href="route('admin.stock.alerts')" />
  @endcan
  @can('lab.view')
    <x-dash.stat :value="number_format($labPending ?? 0)" label="Lab pending" icon="fa-flask"
      :href="route('admin.lab-orders.index')" />
  @endcan
  @can('radiology.view')
    <x-dash.stat :value="number_format($radPending ?? 0)" label="Radiology pending" icon="fa-x-ray"
      :href="route('admin.radiology-orders.index')" />
  @endcan
</div>
