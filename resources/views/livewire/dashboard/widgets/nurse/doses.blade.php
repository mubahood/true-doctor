@php $slotLabels = collect(\App\Enums\DoseSlot::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all(); @endphp
<x-dash.section title="Medication round — doses due" icon="fa-pills">
  <x-dash.bars :data="$bySlot" :labels="$slotLabels" />
</x-dash.section>
