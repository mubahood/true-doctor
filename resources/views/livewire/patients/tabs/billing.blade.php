@php
  $d = fn ($iso, $f = 'd M Y') => $iso ? \Illuminate\Support\Carbon::parse($iso)->format($f) : '—';
  $aging = $this->aging;
@endphp
<div class="tb-stack">
  {{-- ── Invoices ───────────────────────────────────────────── --}}
  <div class="tb-card">
    <div class="tb-card-header"><span class="tb-card-title">Invoices</span><span class="muted tb-small">{{ number_format($invoices->total()) }}</span></div>
    @if($invoices->total() === 0)
      <div class="tb-card-body"><x-ui.empty icon="fa-file-invoice-dollar" noun="invoices" /></div>
    @else
      <div class="tb-table-wrap" wire:loading.class.delay="tb-loading" wire:target="gotoPage,previousPage,nextPage">
        <table class="tb-table">
          <caption class="sr-only">Invoices of this patient</caption>
          <thead>
            <tr>
              <th scope="col" class="tb-serial">#</th><th scope="col">Invoice</th><th scope="col">Date</th><th scope="col">Status</th>
              <th scope="col" class="tb-text-right">Total</th><th scope="col" class="tb-text-right">Paid</th><th scope="col" class="tb-text-right">Balance</th>
              <th scope="col"><span class="sr-only">PDF</span></th>
            </tr>
          </thead>
          <tbody>
            @foreach($invoices as $i)
              <tr wire:key="bi-{{ $i['uuid'] }}">
                <x-ui.serial :rows="$invoices" :loop="$loop" />
                <td class="mono rowlink"><x-ui.link :href="route('admin.invoices.show', $i['uuid'])">{{ $i['invoice_no'] }}</x-ui.link></td>
                <td class="tb-nowrap">{{ $d($i['date']) }}</td>
                <td><x-ui.badge :tone="$i['status_badge']">{{ $i['status'] }}</x-ui.badge></td>
                <td class="tb-text-right"><x-ui.money :amount="$i['total']" /></td>
                <td class="tb-text-right"><x-ui.money :amount="$i['amount_paid']" /></td>
                <td class="tb-text-right @if(bccomp($i['balance'], '0', 2) > 0 && $i['status'] !== 'Void') tb-danger @endif"><x-ui.money :amount="$i['balance']" /></td>
                <td class="tb-text-right">
                  <x-ui.link :href="route('admin.invoices.pdf', $i['uuid'])" :navigate="false" target="_blank" rel="noopener"
                             class="btn-tb btn-tb-ghost btn-tb-sm" aria-label="Invoice {{ $i['invoice_no'] }} as PDF"><i class="fas fa-file-pdf" aria-hidden="true"></i> PDF</x-ui.link>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      {{ $invoices->links() }}
    @endif
  </div>

  {{-- ── Payments ───────────────────────────────────────────── --}}
  <div class="tb-card">
    <div class="tb-card-header"><span class="tb-card-title">Payments</span><span class="muted tb-small">{{ number_format($payments->total()) }}</span></div>
    @if($payments->total() === 0)
      <div class="tb-card-body"><x-ui.empty icon="fa-money-bill-wave" noun="payments" /></div>
    @else
      <div class="tb-table-wrap" wire:loading.class.delay="tb-loading" wire:target="gotoPage,previousPage,nextPage">
        <table class="tb-table">
          <caption class="sr-only">Payments received from this patient</caption>
          <thead>
            <tr>
              <th scope="col" class="tb-serial">#</th><th scope="col">Date</th><th scope="col">Receipt</th><th scope="col">Method</th>
              <th scope="col" class="tb-text-right">Amount</th><th scope="col">Reference</th><th scope="col">Received by</th>
              <th scope="col"><span class="sr-only">Receipt PDF</span></th>
            </tr>
          </thead>
          <tbody>
            @foreach($payments as $p)
              <tr wire:key="bp-{{ $p['uuid'] }}">
                <x-ui.serial :rows="$payments" :loop="$loop" />
                <td class="tb-nowrap">{{ $d($p['date'], 'd M Y H:i') }}</td>
                <td class="mono rowlink">@if($p['invoice_uuid'])<x-ui.link :href="route('admin.invoices.show', $p['invoice_uuid'])">{{ $p['invoice_no'] }}</x-ui.link>@else — @endif</td>
                <td>{{ $p['method'] }}</td>
                <td class="tb-text-right"><x-ui.money :amount="$p['amount']" /></td>
                <td class="mono">{{ $p['reference'] ?? '—' }}</td>
                <td>{{ $p['received_by'] ?? '—' }}</td>
                <td class="tb-text-right">
                  <x-ui.link :href="route('admin.payments.receipt', $p['uuid'])" :navigate="false" target="_blank" rel="noopener"
                             class="btn-tb btn-tb-ghost btn-tb-sm" aria-label="Receipt as PDF"><i class="fas fa-receipt" aria-hidden="true"></i> Receipt</x-ui.link>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      {{ $payments->links() }}
    @endif
  </div>

  {{-- ── Debts ──────────────────────────────────────────────── --}}
  <div class="tb-card">
    <div class="tb-card-header"><span class="tb-card-title">Debts</span>
      <span class="@if($aging['count']) tb-danger @else muted @endif tb-small">
        @if($aging['count']) <x-ui.money :amount="$aging['total']" /> owed on {{ $aging['count'] }} invoice{{ $aging['count'] === 1 ? '' : 's' }} @else Nothing owed @endif
      </span>
    </div>
    @if($aging['count'] === 0)
      <div class="tb-card-body"><x-ui.empty icon="fa-circle-check" compact message="This patient owes nothing." /></div>
    @else
      <div class="tb-card-body">
        <div class="tb-stats-grid" role="group" aria-label="How old the debt is">
          @foreach($aging['buckets'] as $band => $amount)
            <x-dash.stat icon="fa-hourglass-half" :value="\App\Support\HospitalSettings::money($amount)" :label="$band.' days'"
                         :tone="bccomp($amount, '0', 2) > 0 && $band !== '0-30' ? 'bad' : ''" />
          @endforeach
        </div>
      </div>
      <div class="tb-table-wrap">
        <table class="tb-table">
          <caption class="sr-only">Unpaid invoices</caption>
          <thead><tr><th scope="col" class="tb-serial">#</th><th scope="col">Invoice</th><th scope="col">Raised</th><th scope="col">Age</th><th scope="col">Status</th><th scope="col" class="tb-text-right">Balance</th></tr></thead>
          <tbody>
            @foreach($aging['invoices'] as $i)
              <tr wire:key="bd-{{ $i['uuid'] }}">
                <td class="tb-serial mono">{{ $loop->iteration }}</td>
                <td class="mono rowlink"><x-ui.link :href="route('admin.invoices.show', $i['uuid'])">{{ $i['invoice_no'] }}</x-ui.link></td>
                <td class="tb-nowrap">{{ $d($i['date']) }}</td>
                <td>{{ $i['age_days'] }} day{{ $i['age_days'] === 1 ? '' : 's' }}</td>
                <td><x-ui.badge :tone="$i['status_badge']">{{ $i['status'] }}</x-ui.badge></td>
                <td class="tb-text-right tb-danger"><x-ui.money :amount="$i['balance']" /></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif

    @if($showClaims)
      <div class="tb-card-header"><span class="tb-card-title">Insurance claims awaiting</span>
        <span class="muted tb-small">{{ $this->claims['count'] }} · <x-ui.money :amount="$this->claims['amount']" /></span></div>
      @if($this->claims['count'] === 0)
        <div class="tb-card-body"><x-ui.empty icon="fa-shield-heart" compact message="No claim is waiting on an insurer." /></div>
      @else
        <div class="tb-table-wrap">
          <table class="tb-table">
            <caption class="sr-only">Insurance claims awaiting settlement</caption>
            <thead><tr><th scope="col" class="tb-serial">#</th><th scope="col">Claim</th><th scope="col">Insurer</th><th scope="col">Invoice</th><th scope="col">Status</th><th scope="col" class="tb-text-right">Amount</th></tr></thead>
            <tbody>
              @foreach($this->claimRows as $c)
                <tr wire:key="bc-{{ $c['uuid'] }}">
                  <td class="tb-serial mono">{{ $loop->iteration }}</td>
                  <td class="mono rowlink"><x-ui.link :href="route('admin.insurance-claims.show', $c['uuid'])">{{ $c['claim_no'] }}</x-ui.link></td>
                  <td>{{ $c['insurer'] ?? '—' }}</td>
                  <td class="mono">{{ $c['invoice_no'] ?? '—' }}</td>
                  <td><x-ui.badge :tone="$c['status_badge']">{{ $c['status'] }}</x-ui.badge></td>
                  <td class="tb-text-right"><x-ui.money :amount="$c['amount']" /></td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    @endif

    @if($showCards)
      @php($ledger = $this->cardLedger)
      <div class="tb-card-header"><span class="tb-card-title">Card ledger</span>
        <span class="muted tb-small">Spendable balance <x-ui.money :amount="$ledger['balance']" />@if(bccomp($ledger['debt'], '0', 2) > 0) · <span class="tb-danger">owes the card <x-ui.money :amount="$ledger['debt']" /></span>@endif</span></div>
      @if($ledger['cards'] === [])
        <div class="tb-card-body"><x-ui.empty icon="fa-wallet" compact message="This patient holds no card." /></div>
      @else
        <div class="tb-table-wrap">
          <table class="tb-table">
            <caption class="sr-only">Prepaid cards</caption>
            <thead><tr><th scope="col" class="tb-serial">#</th><th scope="col">Card</th><th scope="col">Insurer</th><th scope="col">Status</th><th scope="col" class="tb-text-right">Balance</th><th scope="col" class="tb-text-right">Can spend</th></tr></thead>
            <tbody>
              @foreach($ledger['cards'] as $c)
                <tr wire:key="bl-{{ $c['uuid'] }}">
                  <td class="tb-serial mono">{{ $loop->iteration }}</td>
                  <td class="mono">{{ $c['masked'] }}</td>
                  <td>{{ $c['insurer'] ?? 'Own funds' }}</td>
                  <td><x-ui.badge :tone="$c['status_badge']">{{ $c['status'] }}</x-ui.badge></td>
                  <td class="tb-text-right"><x-ui.money :amount="$c['balance']" /></td>
                  <td class="tb-text-right"><x-ui.money :amount="$c['spendable']" /></td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    @endif
  </div>
</div>
