@php
  $d = fn ($iso, $f = 'd M Y') => $iso ? \Illuminate\Support\Carbon::parse($iso)->format($f) : '—';
  $r = $this->recent;
  $has = fn (string $s) => in_array($s, $this->sections, true);
  $more = fn (string $tab, string $label = 'View all') => '<button type="button" class="tb-rowbtn tb-small" wire:click="$dispatch(\'patient-tab\', { tab: \''.$tab.'\' })">'.$label.' <i class="fas fa-arrow-right" aria-hidden="true"></i></button>';
@endphp
<div class="tb-stack">
  {{-- ── The timeline ───────────────────────────────────────── --}}
  <div class="tb-card">
    <div class="tb-card-header"><span class="tb-card-title">Recent activity</span></div>
    <div class="tb-card-body">
      @forelse($this->activity as $event)
        @if($loop->first)<ol class="tb-timeline" aria-label="Recent activity">@endif
          <li class="tb-tl-item" wire:key="act-{{ $event['kind'] }}-{{ $event['uuid'] }}-{{ $loop->index }}">
            <div class="t"><i class="fas {{ $event['icon'] }} muted" aria-hidden="true"></i> {{ $event['title'] }}</div>
            <div class="m">{{ $d($event['at'], 'd M Y H:i') }}@if($event['detail']) · {{ $event['detail'] }}@endif</div>
          </li>
        @if($loop->last)</ol>@endif
      @empty
        <x-ui.empty icon="fa-clock-rotate-left" compact message="Nothing has happened on this record yet. Visits, invoices, payments and results will appear here." />
      @endforelse
    </div>
  </div>

  <div class="tb-grid-2">
    @if($has('visits'))
      <div class="tb-card">
        <div class="tb-card-header"><span class="tb-card-title">Latest visits</span>{!! $more('visits') !!}</div>
        <div class="tb-table-wrap">
          <table class="tb-table">
            <caption class="sr-only">Latest visits</caption>
            <thead><tr><th scope="col">Visit</th><th scope="col">Date</th><th scope="col">Status</th></tr></thead>
            <tbody>
              @forelse($r['visits'] ?? [] as $v)
                <tr wire:key="ov-v-{{ $v['uuid'] }}">
                  <td class="mono rowlink"><x-ui.link :href="route('admin.visits.show', $v['uuid'])">{{ $v['visit_no'] }}</x-ui.link></td>
                  <td class="tb-nowrap">{{ $d($v['date']) }}</td>
                  <td><x-ui.badge :tone="$v['state_badge']">{{ $v['state'] }}</x-ui.badge></td>
                </tr>
              @empty
                <tr><td colspan="3" class="muted tb-small">No visits yet.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    @endif

    @if($has('money'))
      <div class="tb-card">
        <div class="tb-card-header"><span class="tb-card-title">Latest invoices</span>{!! $more('billing') !!}</div>
        <div class="tb-table-wrap">
          <table class="tb-table">
            <caption class="sr-only">Latest invoices</caption>
            <thead><tr><th scope="col">Invoice</th><th scope="col">Status</th><th scope="col" class="tb-text-right">Balance</th></tr></thead>
            <tbody>
              @forelse($r['invoices'] ?? [] as $i)
                <tr wire:key="ov-i-{{ $i['uuid'] }}">
                  <td class="mono rowlink"><x-ui.link :href="route('admin.invoices.show', $i['uuid'])">{{ $i['invoice_no'] }}</x-ui.link></td>
                  <td><x-ui.badge :tone="$i['status_badge']">{{ $i['status'] }}</x-ui.badge></td>
                  <td class="tb-text-right"><x-ui.money :amount="$i['balance']" /></td>
                </tr>
              @empty
                <tr><td colspan="3" class="muted tb-small">No invoices yet.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>

      <div class="tb-card">
        <div class="tb-card-header"><span class="tb-card-title">Latest payments</span>{!! $more('billing') !!}</div>
        <div class="tb-table-wrap">
          <table class="tb-table">
            <caption class="sr-only">Latest payments</caption>
            <thead><tr><th scope="col">Date</th><th scope="col">Method</th><th scope="col" class="tb-text-right">Amount</th></tr></thead>
            <tbody>
              @forelse($r['payments'] ?? [] as $p)
                <tr wire:key="ov-p-{{ $p['uuid'] }}">
                  <td class="tb-nowrap">{{ $d($p['date']) }}</td>
                  <td>{{ $p['method'] }}</td>
                  <td class="tb-text-right"><x-ui.money :amount="$p['amount']" /></td>
                </tr>
              @empty
                <tr><td colspan="3" class="muted tb-small">No payments yet.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    @endif

    @if($has('clinical'))
      <div class="tb-card">
        <div class="tb-card-header"><span class="tb-card-title">Current medication</span>{!! $more('clinical') !!}</div>
        <div class="tb-card-body">
          @forelse($r['medication'] ?? [] as $m)
            <div class="tb-small" wire:key="ov-m-{{ $loop->index }}"><b>{{ $m['drug'] }}</b> <span class="muted">{{ $m['dosage'] }} · until {{ $d($m['ends_on']) }}</span></div>
          @empty
            <x-ui.empty icon="fa-pills" compact message="Not on any medication." />
          @endforelse
        </div>
      </div>

      <div class="tb-card">
        <div class="tb-card-header"><span class="tb-card-title">Recent diagnoses</span>{!! $more('clinical') !!}</div>
        <div class="tb-card-body">
          @forelse($r['diagnoses'] ?? [] as $x)
            <div class="tb-small" wire:key="ov-d-{{ $x['visit_uuid'] }}"><b>{{ \Illuminate\Support\Str::limit($x['diagnosis'], 80) }}</b> <span class="muted">{{ $d($x['date']) }}</span></div>
          @empty
            <x-ui.empty icon="fa-notes-medical" compact message="No diagnosis recorded." />
          @endforelse
        </div>
      </div>
    @endif

    @if($has('lab'))
      <div class="tb-card">
        <div class="tb-card-header"><span class="tb-card-title">Lab orders</span>{!! $more('clinical') !!}</div>
        <div class="tb-card-body">
          @forelse($r['lab_orders'] ?? [] as $l)
            <div class="tb-small" wire:key="ov-l-{{ $l['uuid'] }}">
              {{ $d($l['date']) }} · <x-ui.badge :tone="$l['status_badge']">{{ $l['status'] }}</x-ui.badge>
              @if($l['abnormal'])<x-ui.badge tone="danger">{{ $l['abnormal'] }} abnormal</x-ui.badge>@endif
            </div>
          @empty
            <x-ui.empty icon="fa-flask" compact message="No lab orders." />
          @endforelse
        </div>
      </div>
    @endif

    @if($has('imaging'))
      <div class="tb-card">
        <div class="tb-card-header"><span class="tb-card-title">Imaging</span>{!! $more('clinical') !!}</div>
        <div class="tb-card-body">
          @forelse($r['imaging_orders'] ?? [] as $o)
            <div class="tb-small" wire:key="ov-r-{{ $o['uuid'] }}">
              {{ $d($o['date']) }} · {{ implode(', ', $o['studies']) ?: 'Imaging' }} <x-ui.badge :tone="$o['status_badge']">{{ $o['status'] }}</x-ui.badge>
            </div>
          @empty
            <x-ui.empty icon="fa-x-ray" compact message="No imaging." />
          @endforelse
        </div>
      </div>
    @endif

    @if($has('admissions'))
      <div class="tb-card">
        <div class="tb-card-header"><span class="tb-card-title">Admissions</span>{!! $more('clinical') !!}</div>
        <div class="tb-card-body">
          @forelse($r['admissions'] ?? [] as $a)
            <div class="tb-small" wire:key="ov-a-{{ $a['uuid'] }}">
              {{ $a['ward'] ?? 'Ward' }} · {{ $d($a['admitted_at']) }} · {{ $a['nights'] }} night{{ $a['nights'] === 1 ? '' : 's' }}
              <x-ui.badge :tone="$a['status_badge']">{{ $a['status'] }}</x-ui.badge>
            </div>
          @empty
            <x-ui.empty icon="fa-bed" compact message="Never admitted." />
          @endforelse
        </div>
      </div>
    @endif

    @if($has('appointments'))
      <div class="tb-card">
        <div class="tb-card-header"><span class="tb-card-title">Appointments</span>{!! $more('appointments') !!}</div>
        <div class="tb-card-body">
          @forelse($r['appointments'] ?? [] as $a)
            <div class="tb-small" wire:key="ov-ap-{{ $a['uuid'] }}">
              {{ $d($a['scheduled_at'], 'd M Y H:i') }}@if($a['doctor']) · {{ $a['doctor'] }}@endif
              <x-ui.badge :tone="$a['status_badge']">{{ $a['status'] }}</x-ui.badge>
            </div>
          @empty
            <x-ui.empty icon="fa-calendar" compact message="No appointments." />
          @endforelse
        </div>
      </div>
    @endif
  </div>
</div>
