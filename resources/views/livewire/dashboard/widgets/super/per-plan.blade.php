@php $data = collect($perPlan)->mapWithKeys(fn ($r) => [$r['name'] => $r['subscribers']])->all(); @endphp
<x-dash.section title="Active subscribers per plan" icon="fa-layer-group" :href="route('super.plans.index')">
  <x-dash.bars :data="$data" />
</x-dash.section>
