<div class="tb-stats-grid">
  @can('appointments.view')
    <x-dash.stat :value="number_format($appointmentsToday ?? 0)" label="My appointments today" icon="fa-calendar-check"
      :href="route('admin.appointments.index')" />
  @endcan
  @can('visits.view')
    <x-dash.stat :value="number_format($openVisits ?? 0)" label="My open visits" icon="fa-stethoscope"
      tone="warn" :href="route('admin.visits.index')" />
  @endcan
  @can('prescriptions.prescribe')
    <x-dash.stat :value="number_format($rxToday ?? 0)" label="Prescriptions today" icon="fa-prescription" />
  @endcan
  @can('ipd.view')
    <x-dash.stat :value="number_format($inpatients ?? 0)" label="My inpatients" icon="fa-bed"
      :href="route('admin.admissions.board')" />
  @endcan
</div>
