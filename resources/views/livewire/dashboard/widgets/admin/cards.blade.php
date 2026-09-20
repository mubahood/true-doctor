@php($money = app(\App\Support\HospitalSettings::class))
{{-- The card desk (docs/cards.md). Held and owed are opposite sides of the
     same instrument, and one figure for both says neither. --}}
<x-dash.section title="Cards & insurers" icon="fa-wallet"
                :href="route('admin.cards.index')" viewLabel="Cards">
  <div class="tb-card-body">
    <div class="tb-stats-grid">
      <x-dash.stat icon="fa-piggy-bank" tone="ok"
                   :value="$money->format($cards['held'] ?? '0')" label="Held on card"
                   :sub="($cards['cards'] ?? 0).' active '.Str::plural('card', $cards['cards'] ?? 0)" />

      <x-dash.stat icon="fa-hand-holding-dollar"
                   :tone="bccomp($cards['owed'] ?? '0', '0', 2) > 0 ? 'bad' : ''"
                   :value="$money->format($cards['owed'] ?? '0')" label="Owed to the hospital"
                   :sub="($cards['inDebt'] ?? 0).' '.Str::plural('card', $cards['inDebt'] ?? 0).' in debt'"
                   :href="route('admin.cards.index', ['owing' => 1])" />

      <x-dash.stat icon="fa-vault"
                   :value="$money->format($cards['float'] ?? '0')" label="Insurer float"
                   :sub="($cards['insurers'] ?? 0).' '.Str::plural('insurer', $cards['insurers'] ?? 0).' funded'"
                   :href="route('admin.insurance-providers.index')" />
    </div>
  </div>
</x-dash.section>
