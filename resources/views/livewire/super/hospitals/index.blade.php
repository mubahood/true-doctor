<div>
  <h1 class="sr-only">Hospitals</h1>

  {{-- ── The platform at a glance ───────────────────────────────────
       Each figure is also a filter: the number that matters is the one
       somebody wants to open. --}}
  @php($s = $this->stats)
  <div class="tb-stats-grid" style="margin-bottom:16px;">
    <x-dash.stat :value="number_format($s['total'])" icon="fa-hospital" label="Hospitals"
      :sub="$s['new_month'].' joined this month · '.$s['new_week'].' this week'" />
    <x-dash.stat :value="number_format($s['paying'])" icon="fa-sack-dollar" tone="ok" label="Paying"
      wire:click="$set('plan', 'active')" role="button" style="cursor:pointer" />
    <x-dash.stat :value="number_format($s['trialing'])" icon="fa-hourglass-half" label="On trial"
      :tone="$s['ending'] > 0 ? 'warn' : ''"
      :sub="$s['ending'] > 0 ? $s['ending'].' ending within '.$this::ENDING_SOON_DAYS.' days' : 'none ending this week'"
      wire:click="$set('plan', '{{ $s['ending'] > 0 ? 'ending' : 'trialing' }}')" role="button" style="cursor:pointer" />
    <x-dash.stat :value="number_format($s['lapsed'] + $s['no_sub'])" icon="fa-circle-pause" label="Not subscribed"
      :sub="$s['lapsed'].' expired or cancelled · '.$s['no_sub'].' never started'"
      wire:click="$set('plan', 'expired')" role="button" style="cursor:pointer" />
    @if($s['suspended'] > 0)
      <x-dash.stat :value="number_format($s['suspended'])" icon="fa-ban" tone="bad" label="Suspended"
        wire:click="$set('state', 'suspended')" role="button" style="cursor:pointer" />
    @endif
  </div>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input"
             placeholder="Search hospital or owner…" title="Name, slug, or the owner's name, email or phone" aria-label="Search hospitals">
    </div>
    <select wire:model.live="plan" class="tb-select" aria-label="Subscription">
      @foreach($this->planFilters() as $val => $lbl)<option value="{{ $val }}">{{ $lbl }}</option>@endforeach
    </select>
    <select wire:model.live="state" class="tb-select" aria-label="Hospital status">
      <option value="">Any status</option>
      @foreach($this->statuses as $val => $lbl)<option value="{{ $val }}">{{ $lbl }}</option>@endforeach
    </select>
    @if($search !== '' || $plan !== '' || $state !== '')
      <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm" wire:click="clearFilters">
        <i class="fas fa-xmark" aria-hidden="true"></i> Clear
      </button>
    @endif
    <div wire:loading.flex wire:target="search,plan,state,sortBy,gotoPage,previousPage,nextPage,perPage" class="muted tb-small tb-flex" style="gap:6px;"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>

    <div class="tb-toolbar-actions">
      @can('create', \App\Models\Hospital::class)<button type="button" wire:click="create" class="btn-tb btn-tb-primary"><i class="fas fa-plus" aria-hidden="true"></i> New hospital</button>@endcan
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="search,plan,state,sortBy,gotoPage,previousPage,nextPage,perPage"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr>
      <x-ui.th-sort field="name" :sort-field="$sortField" :sort-dir="$sortDir">Hospital</x-ui.th-sort>
      <th>Owner</th>
      <th>Subscription</th>
      <x-ui.th-sort field="patients_count" :sort-field="$sortField" :sort-dir="$sortDir" align="right">Usage</x-ui.th-sort>
      <x-ui.th-sort field="last_active_at" :sort-field="$sortField" :sort-dir="$sortDir">Last active</x-ui.th-sort>
      <x-ui.th-sort field="created_at" :sort-field="$sortField" :sort-dir="$sortDir">Joined</x-ui.th-sort>
      <th><span class="sr-only">Actions</span></th>
    </tr></thead>
    <tbody>
      @forelse($rows as $h)
        @php($sub = $h->subscriptions->first())
        @php($owner = $owners[$h->id] ?? null)
        @php($active = $h->last_active_at ? \Illuminate\Support\Carbon::parse($h->last_active_at) : null)
        <tr wire:key="hosp-{{ $h->id }}">
          <td>
            <button type="button" class="tb-linkish tb-fw-500" wire:click="peek({{ $h->id }})">{{ $h->name }}</button>
            <div class="mono muted tb-small">{{ $h->slug }}</div>
            <div class="tb-flex" style="gap:4px;flex-wrap:wrap;margin-top:3px;">
              @if($h->status->value !== 'active')<x-ui.badge tone="danger">{{ $h->status->label() }}</x-ui.badge>@endif
              @if($demoSlug !== null && $h->slug === $demoSlug)<x-ui.badge tone="neutral">Demo</x-ui.badge>@endif
              @if($h->gclid || $h->gbraid || $h->wbraid || in_array($h->utm_medium, \App\Models\TrafficSession::PAID_MEDIA, true))
                <x-ui.badge tone="info" title="{{ trim(($h->utm_campaign ?? 'Google Ads').' '.($h->landing_section ? '· '.$h->landing_section : '')) }}">From an ad</x-ui.badge>
              @elseif($h->utm_source)
                <x-ui.badge tone="neutral">via {{ $h->utm_source }}</x-ui.badge>
              @endif
            </div>
          </td>
          <td class="tb-small">
            @if($owner)
              <div>{{ $owner->name }}</div>
              @if($owner->email)<a href="mailto:{{ $owner->email }}" class="muted">{{ $owner->email }}</a>@endif
              @if($owner->phone)<div><a href="tel:{{ $owner->phone }}" class="muted">{{ $owner->phone }}</a></div>@endif
            @else
              <span class="muted">No admin account</span>
            @endif
          </td>
          <td>
            @if($sub)
              <x-ui.badge :tone="match ($sub->status->value) { 'active' => 'active', 'trialing' => 'info', 'expired', 'cancelled' => 'danger', default => 'neutral' }">{{ $sub->status->label() }}</x-ui.badge>
              <span class="tb-small">{{ $sub->plan?->name }}</span>
              <div class="tb-small muted">
                @if($sub->status->value === 'trialing' && $sub->trial_ends_at)
                  @php($daysLeft = (int) now()->startOfDay()->diffInDays($sub->trial_ends_at->copy()->startOfDay(), false))
                  <span @class(['tone-warn' => $daysLeft <= $this::ENDING_SOON_DAYS && $daysLeft >= 0, 'tone-bad' => $daysLeft < 0])>
                    Trial {{ $daysLeft < 0 ? 'ended' : 'ends' }} {{ $sub->trial_ends_at->format('j M') }}
                    ({{ $daysLeft === 0 ? 'today' : ($daysLeft > 0 ? 'in '.$daysLeft.' '.\Illuminate\Support\Str::plural('day', $daysLeft) : abs($daysLeft).' '.\Illuminate\Support\Str::plural('day', abs($daysLeft)).' ago') }})
                  </span>
                @elseif($sub->ends_at)
                  {{ $sub->status->value === 'active' ? 'Renews' : 'Ended' }} {{ $sub->ends_at->format('j M Y') }}
                @endif
              </div>
            @else
              <x-ui.badge tone="neutral">None</x-ui.badge>
            @endif
          </td>
          <td class="tb-text-right tb-small tb-nowrap">
            <div>{{ number_format($h->patients_count) }} <span class="muted">{{ \Illuminate\Support\Str::plural('patient', $h->patients_count) }}</span></div>
            <div class="muted">{{ number_format($h->visits_count) }} {{ \Illuminate\Support\Str::plural('visit', $h->visits_count) }} · {{ $h->users_count }} staff</div>
          </td>
          <td class="tb-small tb-nowrap">
            @if($active)
              <span title="{{ $active->format('j M Y H:i') }}" @class(['tone-warn' => $active->lt(now()->subDays(14))])>{{ $active->diffForHumans() }}</span>
            @else
              <span class="muted">Never signed in</span>
            @endif
          </td>
          <td class="tb-small tb-nowrap">
            <div>{{ $h->created_at?->format('j M Y') }}</div>
            <div class="muted" title="{{ $h->created_at?->format('j M Y H:i') }}">{{ $h->created_at?->diffForHumans() }}</div>
          </td>
          <td class="tb-text-right tb-nowrap">
            <x-ui.icon-button label="Quick view of {{ $h->name }}" icon="fa-eye" wire:click="peek({{ $h->id }})" />
            @can('update', $h)<x-ui.icon-button label="Edit {{ $h->name }}" icon="fa-pen" wire:click="edit({{ $h->id }})" />@endcan
          </td>
        </tr>
      @empty
        <tr><td colspan="7"><x-ui.empty icon="fa-hospital" noun="hospitals" :filtered="$search !== '' || $plan !== '' || $state !== ''" wire:click="clearFilters" /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  {{ $rows->links(data: $this->paginationData()) }}

  {{-- ── Quick view ─────────────────────────────────────────────────── --}}
  <x-ui.modal show="showPeek" size="lg" autosaves :title="$this->peeked['hospital']->name ?? 'Hospital'">
    @if($p = $this->peeked)
      @php($h = $p['hospital'])
      @php($sub = $p['subscription'])
      <div class="tb-modal-body">
        <x-ui.peek-head :heading="$h->name" :sub="'Joined '.$h->created_at?->format('j F Y, H:i').' · '.$h->created_at?->diffForHumans()">
          <x-ui.badge :tone="$h->status->value === 'active' ? 'active' : 'danger'">{{ $h->status->label() }}</x-ui.badge>
          @if($sub)<x-ui.badge :tone="$sub->status->value === 'active' ? 'active' : ($sub->status->value === 'trialing' ? 'info' : 'danger')">{{ $sub->status->label() }}</x-ui.badge>@endif
        </x-ui.peek-head>

        <x-ui.peek-figs :figures="[
          ['label' => 'Patients', 'value' => number_format($p['patients'])],
          ['label' => 'Visits', 'value' => number_format($p['visits'])],
          ['label' => 'Staff', 'value' => number_format($p['staffCount'])],
          ['label' => 'Paid to date', 'value' => \App\Support\PlatformCurrency::CHARGE.' '.number_format($p['paid']), 'sub' => $p['payments']->count().' '.\Illuminate\Support\Str::plural('payment', $p['payments']->count())],
        ]" />

        <dl class="tb-peek-facts" style="margin-top:14px;">
          <dt>Owner</dt>
          <dd>
            @if($o = $p['owner'])
              {{ $o->name }}
              @if($o->email) · <a href="mailto:{{ $o->email }}">{{ $o->email }}</a>@endif
              @if($o->phone) · <a href="tel:{{ $o->phone }}">{{ $o->phone }}</a>@endif
            @else — @endif
          </dd>
          <dt>Last active</dt>
          <dd>{{ $p['lastActive'] ? $p['lastActive']->format('j M Y H:i').' · '.$p['lastActive']->diffForHumans() : 'Nobody has signed in yet' }}</dd>
          <dt>Plan</dt>
          <dd>
            @if($sub)
              {{ $sub->plan?->name ?? 'Unknown plan' }} — {{ $sub->status->label() }}
              · started {{ $sub->starts_at?->format('j M Y') }}
              @if($sub->trial_ends_at) · trial ends {{ $sub->trial_ends_at->format('j M Y') }}@endif
              @if($sub->ends_at) · {{ $sub->status->value === 'active' ? 'renews' : 'ends' }} {{ $sub->ends_at->format('j M Y') }}@endif
            @else
              No subscription
            @endif
          </dd>
          <dt>Came from</dt>
          <dd>
            @if($h->utm_source || $h->gclid || $h->landing_referrer)
              {{ collect([$h->utm_source, $h->utm_medium])->filter()->implode(' / ') ?: ($h->gclid ? 'google / cpc' : $h->landing_referrer) }}
              @if($h->utm_campaign) · {{ $h->utm_campaign }}@endif
              @if($h->landing_section) · clicked “{{ \App\Support\LandingIntent::assetName($h->landing_section, $h->landing_module, $h->landing_plan) }}”@endif
              @if($h->utm_term) · searched “{{ $h->utm_term }}”@endif
            @else
              Not recorded (direct, or before tracking began)
            @endif
          </dd>
          <dt>Address</dt><dd>{{ $h->address ?: '—' }}</dd>
          <dt>Settings</dt><dd>{{ $h->currency }} · {{ $h->timezone }} · <span class="mono">{{ $h->slug }}</span></dd>
        </dl>

        <div class="tb-peek-sec" style="margin-top:16px;">Staff ({{ $p['staffCount'] }})</div>
        <div class="tb-table-wrap"><table class="tb-table">
          <thead><tr><th>Name</th><th>Role</th><th>Contact</th><th>Last active</th></tr></thead>
          <tbody>
            @forelse($p['staff'] as $u)
              <tr>
                <td class="tb-small">{{ $u->name }} @unless($u->is_active)<x-ui.badge tone="neutral">inactive</x-ui.badge>@endunless</td>
                <td class="tb-small muted">{{ \Illuminate\Support\Str::headline($u->role) }}</td>
                <td class="tb-small">{{ $u->email }}@if($u->phone)<div class="muted">{{ $u->phone }}</div>@endif</td>
                <td class="tb-small muted tb-nowrap">{{ $u->last_active_at?->diffForHumans() ?? 'never' }}</td>
              </tr>
            @empty
              <tr><td colspan="4" class="muted tb-small">No staff accounts</td></tr>
            @endforelse
          </tbody>
        </table></div>
        @if($p['staffCount'] > $p['staff']->count())<p class="muted tb-small">…and {{ $p['staffCount'] - $p['staff']->count() }} more.</p>@endif

        <div class="tb-peek-sec" style="margin-top:16px;">Subscription history</div>
        <div class="tb-table-wrap"><table class="tb-table">
          <thead><tr><th>Plan</th><th>Status</th><th>From</th><th>Until</th></tr></thead>
          <tbody>
            @forelse($p['subscriptions'] as $row)
              <tr>
                <td class="tb-small">{{ $row->plan?->name ?? '—' }}</td>
                <td class="tb-small">{{ $row->status->label() }}</td>
                <td class="tb-small tb-nowrap">{{ $row->starts_at?->format('j M Y') }}</td>
                <td class="tb-small tb-nowrap">{{ ($row->trial_ends_at ?? $row->ends_at)?->format('j M Y') ?? '—' }}</td>
              </tr>
            @empty
              <tr><td colspan="4" class="muted tb-small">None</td></tr>
            @endforelse
          </tbody>
        </table></div>

        @if($p['payments']->isNotEmpty())
          <div class="tb-peek-sec" style="margin-top:16px;">Payments</div>
          <div class="tb-table-wrap"><table class="tb-table">
            <thead><tr><th>Date</th><th class="tb-text-right">Amount</th><th>Method</th><th>Reference</th></tr></thead>
            <tbody>
              @foreach($p['payments'] as $pay)
                <tr>
                  <td class="tb-small tb-nowrap">{{ \Illuminate\Support\Carbon::parse($pay->paid_at)->format('j M Y') }}</td>
                  <td class="tb-small tb-text-right">{{ number_format((float) $pay->amount) }}</td>
                  <td class="tb-small">{{ ucfirst((string) $pay->method) }}</td>
                  <td class="tb-small mono muted">{{ $pay->reference ?? '—' }}</td>
                </tr>
              @endforeach
            </tbody>
          </table></div>
        @endif
      </div>
      <x-ui.peek-foot>
        <x-slot:actions>
          @can('update', $h)
            <button type="button" class="btn-tb btn-tb-primary" wire:click="editFromPeek({{ $h->id }})">
              <i class="fas fa-pen" aria-hidden="true"></i> Edit hospital
            </button>
          @endcan
        </x-slot:actions>
      </x-ui.peek-foot>
    @endif
  </x-ui.modal>

  {{-- ── Slide-over: create / edit ─────────────────────────────── --}}
  <x-ui.modal show="showForm" :title="$editingId ? 'Edit hospital' : 'New hospital'">
    @if($showForm)
    <form wire:submit="save" style="display:contents;">
      <div class="tb-modal-body">
        <x-ui.field label="Name" for="hosp-name" name="name" required>
          <input id="hosp-name" type="text" wire:model="name" class="tb-input" required>
        </x-ui.field>
        <x-ui.field label="Slug" for="hosp-slug" name="slug" hint="Auto-generated from the name if left blank.">
          <input id="hosp-slug" type="text" wire:model="slug" class="tb-input" placeholder="e.g. city-clinic">
        </x-ui.field>
        <x-ui.field label="Address" for="hosp-address" name="address">
          <textarea id="hosp-address" wire:model="address" class="tb-textarea" rows="2"></textarea>
        </x-ui.field>
        <div class="tb-form-grid">
          <x-ui.field label="Timezone" for="hosp-tz" name="timezone" required>
            <input id="hosp-tz" type="text" wire:model="timezone" class="tb-input" required>
          </x-ui.field>
          <x-ui.field label="Currency" for="hosp-currency" name="currency" required>
            <input id="hosp-currency" type="text" wire:model="currency" class="tb-input" maxlength="3" style="text-transform:uppercase;" required>
          </x-ui.field>
          <x-ui.field label="Status" for="hosp-status" name="status" required>
            <select id="hosp-status" wire:model="status" class="tb-select">
              @foreach($this->statuses as $val => $lbl)<option value="{{ $val }}">{{ $lbl }}</option>@endforeach
            </select>
          </x-ui.field>
        </div>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="save">
          <span wire:loading.remove wire:target="save"><i class="fas fa-check" aria-hidden="true"></i> {{ $editingId ? 'Save changes' : 'Create hospital' }}</span>
          <span wire:loading wire:target="save"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
        </button>
      </div>
    </form>
    @endif
  </x-ui.modal>
</div>
