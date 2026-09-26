<div class="dash-root">
  <x-ui.page-header title="Traffic &amp; campaigns" :crumbs="['Platform' => route('super.hospitals.index'), 'Traffic' => null]">
    <x-slot:explain>
      Which advertisement brought a hospital, and where the rest dropped off.
      Ad tables count <strong>clicks</strong> (what Google bills) and follow the
      people behind them to a trial and a payment. Crawlers are left out of
      every figure and counted on their own.
    </x-slot:explain>
    <x-slot:actions>
      <button type="button" class="btn-tb" wire:click="exportConversions"
              title="Offline conversion import. Create conversion actions named “{{ $this::CONVERSION_TRIAL }}” and “{{ $this::CONVERSION_PAID }}” in Google Ads first.">
        <i class="fas fa-file-csv" aria-hidden="true"></i> Google Ads conversions
      </button>
    </x-slot:actions>
  </x-ui.page-header>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-seg" role="group" aria-label="Period">
      @foreach($this->presets() as $key => $label)
        <button type="button" class="tb-seg-btn" wire:click="usePreset('{{ $key }}')">{{ $label }}</button>
      @endforeach
    </div>
    <div class="tb-seg" role="group" aria-label="Channel">
      @foreach($this->channels() as $key => $label)
        <button type="button" class="tb-seg-btn {{ $channel === $key ? 'is-on' : '' }}"
                aria-pressed="{{ $channel === $key ? 'true' : 'false' }}"
                wire:click="useChannel('{{ $key }}')">{{ $label }}</button>
      @endforeach
    </div>
    <x-ui.field label="From" for="t-from" name="from">
      <input id="t-from" type="date" wire:model.live="from" class="tb-input">
    </x-ui.field>
    <x-ui.field label="To" for="t-to" name="to">
      <input id="t-to" type="date" wire:model.live="to" class="tb-input">
    </x-ui.field>
    <div wire:loading.flex wire:target="from,to,usePreset,useChannel,search" class="muted tb-small tb-flex">
      <i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…
    </div>
  </div>

  {{-- ── The headline ─────────────────────────────────────────────── --}}
  @php($t = $this->totals)
  @php($mins = intdiv($t['avg_seconds'], 60))
  <div class="tb-stats-grid">
    <x-dash.stat :value="number_format($t['visitors'])" icon="fa-users" label="Visitors"
      :sub="number_format($t['views']).' pages · '.$t['avg_pages'].' each'" />
    <x-dash.stat :value="number_format($t['clicks'])" icon="fa-rectangle-ad" label="Ad clicks"
      sub="fresh clicks, not reloads or redirects" />
    <x-dash.stat :value="number_format($t['trials'])" icon="fa-hospital" tone="ok" label="Trials started"
      :sub="$t['rate'].'% of visitors'" />
    <x-dash.stat :value="number_format($t['customers'])" icon="fa-sack-dollar" tone="ok" label="Became paying"
      sub="trials from these visitors that paid" />
    <x-dash.stat :value="$t['bounce'].'%'" icon="fa-person-walking-arrow-right" :tone="$t['bounce'] >= 70 ? 'warn' : ''" label="Left after one page"
      :sub="'engaged stay '.($mins > 0 ? $mins.'m ' : '').($t['avg_seconds'] % 60).'s on average'" />
    <x-dash.stat :value="number_format($t['bots'])" icon="fa-robot" label="Crawlers"
      sub="excluded from every figure here" />
  </div>

  {{-- ── Where the money leaks ────────────────────────────────────────
       Every step as a share of everybody who arrived, so any two can be
       compared without doing sums. --}}
  <div class="dash-grid cols-2 tops">
    <x-dash.section title="From click to customer" icon="fa-filter">
      <div class="tb-card-body"><div class="dash-bars">
        @foreach($this->funnel as $step)
          <div class="dash-bar-row">
            <span class="bl">{{ $step['label'] }}</span>
            <span class="dash-bar-track"><span class="dash-bar-fill" style="width:{{ min(100, $step['pct']) }}%"></span></span>
            <span class="bv">{{ number_format($step['count']) }} <span class="muted tb-small">· {{ $step['pct'] }}%</span></span>
          </div>
        @endforeach
      </div>
      <p class="muted tb-small">
        Also: {{ number_format($t['demo']) }} opened the demo,
        {{ number_format($t['enquiries']) }} sent an enquiry,
        {{ number_format($t['returning']) }} came back another day.
      </p></div>
    </x-dash.section>

    <x-dash.section title="Visitors, day by day" icon="fa-chart-line">
      <x-dash.sparkline :series="$this->daily" :money="false" />
    </x-dash.section>
  </div>

  {{-- ── What the advertising bought ──────────────────────────────────
       Sorted by customers, then trials, then clicks: a sitelink with a
       thousand clicks and nothing after them is one losing money, and
       sorting by clicks puts it proudly at the top. --}}
  @php($adTables = [
    ['Ad assets — sitelinks, price items and the main ad', 'fa-link', $this->byAsset, 'Asset', 'No ad clicks in this period'],
    ['Ads (utm_content)', 'fa-rectangle-ad', $this->byAd, 'Ad', 'No ad clicks in this period'],
    ['Campaigns', 'fa-bullhorn', $this->byCampaign, 'Campaign', 'No ad clicks in this period'],
    ['Search terms', 'fa-magnifying-glass', $this->byKeyword, 'Keyword', 'None recorded. Add utm_term={keyword} to the tracking suffix to see them.'],
  ])
  @foreach($adTables as [$title, $icon, $rows, $heading, $empty])
    <x-dash.section :title="$title" :icon="$icon">
      <div class="tb-table-wrap"><table class="tb-table">
        <thead><tr>
          <th>{{ $heading }}</th>
          <th class="tb-text-right" title="Fresh ad clicks — what Google bills">Clicks</th>
          <th class="tb-text-right" title="Different people behind those clicks">People</th>
          <th class="tb-text-right" title="Of those people, share who left after one page">Bounce</th>
          <th class="tb-text-right" title="Opened the sign-up form">Form</th>
          <th class="tb-text-right">Trials</th>
          <th class="tb-text-right" title="Trials that went on to pay">Paid</th>
          <th class="tb-text-right" title="Trials per person">Rate</th>
        </tr></thead>
        <tbody>
          @forelse($rows as $row)
            <tr>
              <td>{{ $row['label'] }}</td>
              <td class="tb-text-right">{{ number_format($row['clicks']) }}</td>
              <td class="tb-text-right">{{ number_format($row['visitors']) }}</td>
              <td class="tb-text-right {{ $row['bounce'] >= 80 && $row['visitors'] >= 5 ? 'tone-warn' : '' }}">{{ $row['bounce'] }}%</td>
              <td class="tb-text-right">{{ number_format($row['form']) }}</td>
              <td class="tb-text-right">{{ number_format($row['trials']) }}</td>
              <td class="tb-text-right {{ $row['customers'] > 0 ? 'tone-ok' : '' }}">{{ number_format($row['customers']) }}</td>
              <td class="tb-text-right {{ $row['trials'] > 0 ? 'tone-ok' : 'muted' }}">{{ $row['rate'] }}%</td>
            </tr>
          @empty
            <tr><td colspan="8"><x-dash.empty icon="fa-chart-simple" :text="$empty" /></td></tr>
          @endforelse
        </tbody>
      </table></div>
    </x-dash.section>
  @endforeach

  {{-- ── Whether the landing URLs work ─────────────────────────────── --}}
  <x-dash.section title="Landing URL health" icon="fa-heart-pulse">
    <div class="tb-table-wrap"><table class="tb-table">
      <thead><tr>
        <th>Path</th>
        <th class="tb-text-right">Hits</th>
        <th class="tb-text-right" title="Answered with a redirect (a sitelink on / sending them on)">Redirects</th>
        <th class="tb-text-right" title="Answered 4xx or 5xx — Google disapproves ads whose URLs fail">Errors</th>
        <th class="tb-text-right">Avg</th>
        <th class="tb-text-right">Slowest</th>
      </tr></thead>
      <tbody>
        @forelse($this->health as $row)
          <tr>
            <td class="mono">{{ $row['path'] }}</td>
            <td class="tb-text-right">{{ number_format($row['hits']) }}</td>
            <td class="tb-text-right muted">{{ number_format($row['redirects']) }}</td>
            <td class="tb-text-right {{ $row['errors'] > 0 ? 'tone-bad' : 'muted' }}">{{ number_format($row['errors']) }}</td>
            <td class="tb-text-right {{ $row['slow'] ? 'tone-warn' : '' }}">{{ number_format($row['avg_ms']) }} ms</td>
            <td class="tb-text-right muted">{{ number_format($row['max_ms']) }} ms</td>
          </tr>
        @empty
          <tr><td colspan="6"><x-dash.empty icon="fa-heart-pulse" text="Nothing in this period" /></td></tr>
        @endforelse
      </tbody>
    </table></div>
    <p class="muted tb-small tb-card-body">Every hit, crawlers included — Google's own checker is one. Server time only; the page still has to download.</p>
  </x-dash.section>

  {{-- ── Who they were ─────────────────────────────────────────────── --}}
  <div class="dash-grid cols-2 tops">
    @foreach([
      ['Source', 'fa-diagram-project', $this->bySource, 'Source'],
      ['Referring site', 'fa-arrow-right-to-bracket', $this->byReferrer, 'Site'],
      ['Country', 'fa-earth-africa', $this->byCountry, 'Country'],
      ['Device', 'fa-mobile-screen', $this->byDevice, 'Device'],
    ] as [$title, $icon, $rows, $heading])
      <x-dash.section :title="$title" :icon="$icon">
        <div class="tb-table-wrap"><table class="tb-table">
          <thead><tr>
            <th>{{ $heading }}</th>
            <th class="tb-text-right">Visitors</th>
            <th class="tb-text-right">Trials</th>
            <th class="tb-text-right">Rate</th>
          </tr></thead>
          <tbody>
            @forelse($rows as $row)
              <tr>
                <td>{{ $row['label'] }}</td>
                <td class="tb-text-right">{{ number_format($row['sessions']) }}</td>
                <td class="tb-text-right">{{ number_format($row['signups']) }}</td>
                <td class="tb-text-right {{ $row['signups'] > 0 ? 'tone-ok' : 'muted' }}">{{ $row['rate'] }}%</td>
              </tr>
            @empty
              <tr><td colspan="4"><x-dash.empty icon="fa-chart-simple" text="Nothing in this period" /></td></tr>
            @endforelse
          </tbody>
        </table></div>
      </x-dash.section>
    @endforeach
  </div>

  {{-- ── One visitor at a time ────────────────────────────────────────
       The breakdowns say what is happening; this says what happened to
       somebody. Both are needed — an average never explains a drop-off. --}}
  <x-dash.section :title="$search !== '' ? 'Visits matching “'.$search.'” (all time)' : 'Recent visitors'" icon="fa-clock-rotate-left" :count="$this->recent->count()">
    <div class="tb-filter-bar tb-toolbar tb-card-body">
      <div class="tb-search-wrap">
        <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
        <input type="search" wire:model.live.debounce.400ms="search" class="tb-input"
               placeholder="Trace a gclid, visit id, campaign or hospital…" aria-label="Trace a visit">
      </div>
    </div>
    <div class="tb-table-wrap"><table class="tb-table">
      <thead><tr>
        <th>Last seen</th><th>From</th><th>First asked for</th><th>Country</th><th>Device</th>
        <th class="tb-text-right" title="Pages read · ad clicks · visits">Pages · clicks · visits</th><th>Got as far as</th><th><span class="sr-only">Trail</span></th>
      </tr></thead>
      <tbody>
        @forelse($this->recent as $s)
          <tr wire:key="tv-{{ $s->id }}">
            <td class="tb-small">{{ $s->last_seen_at?->format('d M H:i') }}</td>
            <td>
              {{ $s->sourceLabel() }}
              @if($s->isPaid())<span class="badge-tb badge-info" title="A click somebody paid for">paid</span>@endif
              @if($s->is_bot)<span class="badge-tb badge-neutral">crawler</span>@endif
            </td>
            <td class="tb-small muted">{{ \App\Support\LandingIntent::assetName($s->landing_section, $s->landing_module, $s->landing_plan) }}</td>
            <td class="tb-small">{{ $s->country ?? '—' }}</td>
            <td class="tb-small">{{ $s->device ?? '—' }}</td>
            <td class="tb-text-right tb-small">{{ $s->page_views }} · {{ $s->clicks }} · {{ $s->visits }}</td>
            <td>
              @if($s->hospital)
                <span class="badge-tb badge-success">{{ $s->hospital->name }}</span>
              @elseif($s->converted_hospital_id)
                <span class="badge-tb badge-neutral">hospital deleted</span>
              @elseif($s->opened_signup_at)
                <span class="badge-tb badge-warn">sign-up form</span>
              @elseif($s->enquired_at)
                <span class="badge-tb badge-info">enquired</span>
              @elseif($s->viewed_pricing_at)
                <span class="muted tb-small">pricing</span>
              @else
                <span class="muted tb-small">—</span>
              @endif
            </td>
            <td class="tb-text-right">
              <button type="button" class="tb-rowbtn" wire:click="showTrail({{ $s->id }})" title="Everything this visitor did" aria-label="Show trail">
                <i class="fas fa-shoe-prints" aria-hidden="true"></i>
              </button>
            </td>
          </tr>
        @empty
          <tr><td colspan="8"><x-dash.empty icon="fa-clock-rotate-left" :text="$search !== '' ? 'No visit matches that' : 'Nobody has arrived in this period'" /></td></tr>
        @endforelse
      </tbody>
    </table></div>
  </x-dash.section>

  {{-- The trail of one visitor. --}}
  <x-ui.modal show="trail" title="Everything this visitor did" size="lg" :autosaves="true">
    <div class="tb-modal-body">
      @if($v = $this->trailSession)
        <dl class="tb-peek-facts" style="margin-bottom:14px">
          <dt>Visit id</dt><dd class="mono">{{ $v->uuid }}</dd>
          <dt>From</dt><dd>{{ $v->sourceLabel() }}@if($v->utm_campaign) · {{ $v->utm_campaign }}@endif @if($v->utm_content) · {{ $v->utm_content }}@endif</dd>
          @if($v->utm_term)<dt>Search term</dt><dd>{{ $v->utm_term }}</dd>@endif
          @if($v->googleClickId())
            <dt>Google click id</dt>
            <dd class="mono">{{ $v->gclid ? 'gclid' : ($v->gbraid ? 'gbraid' : 'wbraid') }} {{ \Illuminate\Support\Str::limit($v->googleClickId(), 48) }}</dd>
          @endif
          @if($v->ad_params)<dt>Ad parameters</dt><dd class="mono">{{ collect($v->ad_params)->map(fn ($val, $k) => $k.'='.$val)->implode(' · ') }}</dd>@endif
          <dt>First asked for</dt><dd>{{ \App\Support\LandingIntent::assetName($v->landing_section, $v->landing_module, $v->landing_plan) }}</dd>
          <dt>Referrer</dt><dd>{{ $v->referrer_host ?? 'none' }}</dd>
          <dt>Device</dt><dd>{{ $v->device }} · {{ $v->browser }} · {{ $v->platform }} · {{ $v->country ?? 'country unknown' }}</dd>
          <dt>Here</dt><dd>{{ $v->first_seen_at?->format('d M Y H:i') }} → {{ $v->last_seen_at?->format('d M Y H:i') }} · {{ $v->visits }} {{ \Illuminate\Support\Str::plural('visit', $v->visits) }} · {{ $v->clicks }} ad {{ \Illuminate\Support\Str::plural('click', $v->clicks) }}</dd>
          <dt>Became</dt><dd>{{ $v->hospital?->name ?? ($v->converted_hospital_id ? 'a hospital since deleted' : 'nothing yet') }}</dd>
          @if($v->is_bot)<dt>Crawler</dt><dd class="mono">{{ $v->user_agent }}</dd>@endif
        </dl>
      @endif

      @if($this->trailEvents->isEmpty())
        <x-dash.empty icon="fa-shoe-prints" text="Nothing recorded" />
      @else
        <div class="tb-table-wrap"><table class="tb-table">
          <thead><tr><th>When</th><th>What</th><th>Page</th><th>Ad</th><th class="tb-text-right">Answer</th></tr></thead>
          <tbody>
            @foreach($this->trailEvents as $e)
              <tr>
                <td class="tb-small">{{ $e->created_at?->format('d M H:i:s') }}</td>
                <td class="tb-small">
                  @switch($e->kind)
                    @case('redirect') <span class="muted">sent on</span> @break
                    @case('enquiry') <span class="badge-tb badge-info">enquiry sent</span> @break
                    @case('signup') <span class="badge-tb badge-success">signed up</span> @break
                    @default opened
                  @endswitch
                  @if($e->is_click)<span class="badge-tb badge-info" title="A fresh ad click">click</span>@endif
                </td>
                <td class="mono tb-small">
                  {{ $e->path }}@if($e->redirect_to) → {{ $e->redirect_to }}@endif
                </td>
                <td class="tb-small muted">
                  @php($bits = collect([
                    $e->section ? \App\Support\LandingIntent::assetName($e->section, $e->module, $e->plan) : null,
                    $e->utm_campaign, $e->utm_content, $e->keyword ? '“'.$e->keyword.'”' : null,
                    $e->click_id_type,
                  ])->filter())
                  {{ $bits->isEmpty() ? '—' : $bits->implode(' · ') }}
                </td>
                <td class="tb-text-right tb-small {{ ($e->status ?? 0) >= 400 ? 'tone-bad' : 'muted' }}">
                  @if($e->status){{ $e->status }}@endif
                  @if($e->duration_ms !== null) · {{ number_format($e->duration_ms) }} ms @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table></div>
      @endif
    </div>
    <div class="tb-modal-foot">
      <button type="button" class="btn-tb btn-tb-ghost" wire:click="closeTrail">Close</button>
    </div>
  </x-ui.modal>
</div>
