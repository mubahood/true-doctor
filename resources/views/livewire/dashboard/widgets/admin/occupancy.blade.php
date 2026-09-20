{{-- A ring of one segment is not a chart. Occupied against available, with
     the figure a reader actually wants — how full the hospital is — in the
     middle of it. --}}
<x-dash.section title="Bed occupancy" icon="fa-hospital"
                :href="route('admin.admissions.board')" viewLabel="Board">
  <x-dash.donut
    :data="['occupied' => $occupancy['occupied'] ?? 0, 'available' => $occupancy['available'] ?? 0]"
    :labels="['occupied' => 'Occupied', 'available' => 'Available']"
    :center="($occupancy['rate'] ?? 0).'%'"
    center-label="full"
    :sub="($occupancy['occupied'] ?? 0).' of '.($occupancy['total'] ?? 0).' beds in use'" />
</x-dash.section>
