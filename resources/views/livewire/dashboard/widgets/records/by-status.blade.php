@php $statusLabels = collect(\App\Enums\PatientStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all(); @endphp
<x-dash.section title="Patients by status" icon="fa-chart-pie">
  <x-dash.donut :data="$byStatus" :labels="$statusLabels" centerLabel="Patients" />
</x-dash.section>
