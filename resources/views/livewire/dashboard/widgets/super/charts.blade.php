@php
    $subLabels = collect(\App\Enums\SubscriptionStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all();
    $hospLabels = collect(\App\Enums\HospitalStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all();
@endphp
<div class="dash-grid cols-2">
  <x-dash.section title="Hospitals by status" icon="fa-chart-pie" :href="route('super.hospitals.index')">
    <x-dash.donut :data="$byStatus" :labels="$hospLabels" centerLabel="Hospitals" />
  </x-dash.section>
  <x-dash.section title="Subscriptions by status" icon="fa-file-invoice-dollar" :href="route('super.subscriptions.index')">
    <x-dash.bars :data="$subs" :labels="$subLabels" />
  </x-dash.section>
</div>
