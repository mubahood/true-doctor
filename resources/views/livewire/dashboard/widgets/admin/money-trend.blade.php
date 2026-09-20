{{-- Two lines, one scale: what the hospital asked for, and what it got. --}}
<x-dash.section title="Billed & collected · last {{ $trend['days'] ?? 14 }} days" icon="fa-chart-line"
                :href="route('admin.reports.index')" viewLabel="Reports">
  <x-dash.trend :billed="$trend['billed'] ?? []" :collected="$trend['collected'] ?? []"
                :billed-total="$trend['billedTotal'] ?? '0'"
                :collected-total="$trend['collectedTotal'] ?? '0'" />
</x-dash.section>
