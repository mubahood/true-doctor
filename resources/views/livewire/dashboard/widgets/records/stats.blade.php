<div class="tb-stats-grid">
  <x-dash.stat :value="number_format($total)" label="Total patients" icon="fa-user-injured"
    :href="route('admin.patients.index')" />
  <x-dash.stat :value="number_format($newToday)" label="New today" icon="fa-user-plus" tone="ok" />
  <x-dash.stat :value="number_format($newWeek)" label="New this week" icon="fa-calendar-week" />
  <x-dash.stat :value="number_format($byStatus['active'] ?? 0)" label="Active patients" icon="fa-user-check" />
</div>
