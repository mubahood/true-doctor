@php $money = app(\App\Support\HospitalSettings::class); @endphp
<div class="tb-stats-grid">
  <x-dash.stat :value="$money->format($revenueToday)" label="Revenue today" icon="fa-coins" tone="ok"
    :sub="$paymentsToday.' payments'" />
  <x-dash.stat :value="$money->format($revenueMonth)" label="Revenue this month" icon="fa-sack-dollar" tone="ok" />
  <x-dash.stat :value="$money->format($outstanding['total'])" label="Outstanding" icon="fa-file-invoice-dollar"
    :tone="$outstanding['count'] ? 'warn' : ''" :sub="$outstanding['count'].' unpaid'" :href="route('admin.invoices.index')" />
  <x-dash.stat :value="number_format($claims['count'])" label="Pending claims" icon="fa-shield-heart"
    :sub="$money->format($claims['total'])" :href="route('admin.insurance-claims.index')" />
</div>
