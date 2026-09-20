<div class="tb-stats-grid">
  <x-dash.stat :value="number_format($pending)" label="Pending orders" icon="fa-flask" tone="warn"
    :href="route('admin.lab-orders.index')" />
  <x-dash.stat :value="number_format($stages['processing'] ?? 0)" label="In processing" icon="fa-vials"
    :href="route('admin.lab-orders.index')" />
  <x-dash.stat :value="number_format($stages['collected'] ?? 0)" label="Awaiting processing" icon="fa-vial-circle-check" />
  <x-dash.stat :value="number_format($completedToday)" label="Completed today" icon="fa-clipboard-check" tone="ok" />
</div>
