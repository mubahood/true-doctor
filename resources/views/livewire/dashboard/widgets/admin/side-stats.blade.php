@php $money = app(\App\Support\HospitalSettings::class); @endphp
<div>
  {{-- Reference numbers rather than today's work: the register's size, the
       headcount, the two queues that have their own module. --}}
  @canany(['patients.view','finance.view'])
    <div class="tb-stats-grid">
      @can('patients.view')
        <x-dash.stat :value="number_format($patientsTotal ?? 0)" label="Patients" icon="fa-user-injured"
          :sub="($patientsNewToday ?? 0).' new today'" :href="route('admin.patients.index')" />
      @endcan
      @can('finance.view')
        <x-dash.stat :value="number_format($claims['count'] ?? 0)" label="Pending claims" icon="fa-shield-heart"
          :sub="$money->format($claims['total'] ?? '0')" :href="route('admin.insurance-claims.index')" />
        <x-dash.stat :value="number_format($staff ?? 0)" label="Staff" icon="fa-user-shield"
          :href="route('admin.users.index')" />
      @endcan
    </div>
  @endcanany
  @canany(['lab.view','radiology.view'])
    <div class="tb-stats-grid">
      @can('lab.view')
        <x-dash.stat :value="number_format($labPending ?? 0)" label="Lab pending" icon="fa-flask"
          :href="route('admin.lab-orders.index')" />
      @endcan
      @can('radiology.view')
        <x-dash.stat :value="number_format($radPending ?? 0)" label="Radiology pending" icon="fa-x-ray"
          :href="route('admin.radiology-orders.index')" />
      @endcan
    </div>
  @endcanany
</div>
