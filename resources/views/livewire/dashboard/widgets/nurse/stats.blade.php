<div class="tb-stats-grid">
  @can('visits.vitals')
    <x-dash.stat :value="number_format($notStarted ?? 0)" label="Awaiting vitals" icon="fa-heart-pulse" tone="warn"
      :href="route('admin.visits.index')" />
  @endcan
  @can('prescriptions.administer')
    <x-dash.stat :value="number_format($dosesDue ?? 0)" label="Doses due today" icon="fa-pills"
      :sub="($dosesMissed ?? 0).' missed'" />
  @endcan
  @can('ipd.view')
    <x-dash.stat :value="number_format($inpatients ?? 0)" label="Inpatients" icon="fa-bed"
      :href="route('admin.admissions.board')" />
  @endcan
  @can('treatments.manage')
    <x-dash.stat :value="number_format($procedures ?? 0)" label="Procedures today" icon="fa-syringe" />
  @endcan
</div>
