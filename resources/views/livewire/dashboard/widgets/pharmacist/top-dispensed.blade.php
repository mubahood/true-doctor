@php $topData = collect($top)->mapWithKeys(fn ($r) => [$r['name'] => $r['qty']])->all(); @endphp
<x-dash.section title="Top dispensed today" icon="fa-chart-simple">
  <x-dash.bars :data="$topData" />
</x-dash.section>
