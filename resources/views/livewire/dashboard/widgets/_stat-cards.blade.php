{{--
  A role's stat cards, drawn from App\Support\Dashboard\StatCards — the same
  list GET /api/v1/dashboard serves the app, so a label, icon or link changed
  there changes on the web and in the app together. `$data` is the widget's
  payload, inherited from the section that includes this.
--}}
<div class="tb-stats-grid">
  @foreach(\App\Support\Dashboard\StatCards::for($roleView, $data, auth()->user()) as $card)
    <x-dash.stat :value="$card['value']" :label="$card['label']" :icon="$card['icon']" :tone="$card['tone']"
      :sub="$card['sub']" :href="$card['route'] ? route($card['route']) : null" />
  @endforeach
</div>
