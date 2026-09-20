<x-dash.section title="Revenue — last 14 days" icon="fa-chart-line" :href="route('admin.reports.index')" viewLabel="Reports">
  <x-dash.sparkline :series="$trend" />
</x-dash.section>
