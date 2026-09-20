<div class="tb-stats-grid">
  <x-dash.stat :value="number_format($awaitingReport)" label="Awaiting report" icon="fa-file-waveform" tone="warn"
    :href="route('admin.radiology-orders.index')" />
  <x-dash.stat :value="number_format($pending)" label="Pending orders" icon="fa-x-ray"
    :href="route('admin.radiology-orders.index')" />
  <x-dash.stat :value="number_format($reportedToday)" label="Reported today" icon="fa-clipboard-check" tone="ok" />
</div>
