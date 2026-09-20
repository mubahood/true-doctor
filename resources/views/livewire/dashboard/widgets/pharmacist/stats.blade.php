@php $money = app(\App\Support\HospitalSettings::class); @endphp
<div class="tb-stats-grid">
  <x-dash.stat :value="number_format($lowStock)" label="Low stock items" icon="fa-triangle-exclamation"
    :tone="$lowStock ? 'bad' : ''" :href="route('admin.stock.alerts')" />
  <x-dash.stat :value="number_format($expiring)" label="Expiring ≤90 days" icon="fa-hourglass-half"
    :tone="$expiring ? 'warn' : ''" :href="route('admin.stock.index')" />
  <x-dash.stat :value="$money->format($valuation['total_value'])" label="Stock value" icon="fa-boxes-stacked"
    :sub="number_format($valuation['items']).' active items'" :href="route('admin.stock.index')" />
  <x-dash.stat :value="number_format($dispensations)" label="Dispensations today" icon="fa-prescription-bottle-medical" tone="ok" />
</div>
