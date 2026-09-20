@php $money = app(\App\Support\HospitalSettings::class); @endphp
<div class="tb-stats-grid">
  <x-dash.stat :value="number_format($hospitals)" label="Hospitals" icon="fa-hospital"
    :href="route('super.hospitals.index')" />
  <x-dash.stat :value="number_format($subs['active'] ?? 0)" label="Active subscriptions" icon="fa-circle-check" tone="ok"
    :href="route('super.subscriptions.index')" />
  <x-dash.stat :value="number_format($trialsExpiring)" label="Trials expiring ≤7d" icon="fa-hourglass-half"
    :tone="$trialsExpiring ? 'warn' : ''" :href="route('super.subscriptions.index')" />
  <x-dash.stat :value="$money->format($mrr)" label="Est. MRR" icon="fa-arrow-trend-up" tone="ok" />
</div>
