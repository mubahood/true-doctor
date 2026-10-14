@php($f = $this->figures)
@php($s = $f['statement'])
@php($income = bcadd($s['totals']['net_revenue'], $s['other_income']['total'], 2))
@php($spent = bcadd($s['cost_of_sales']['total'], $s['expenses']['total'], 2))
<div>
  <x-ui.page-header title="Finance overview">
    <x-slot:explain>Your books, kept for you. Every invoice, payment, card top-up, insurer deposit and stock movement is posted here as it happens; what does not come through a patient's bill — rent, salaries, a grant — you record with Money in and Money out.</x-slot:explain>
    <x-slot:actions>
      @can('finance.record')
        <button type="button" class="btn-tb" wire:click="openMoney('in')"><i class="fas fa-arrow-down" aria-hidden="true"></i> Money in</button>
        <button type="button" class="btn-tb" wire:click="openMoney('out')"><i class="fas fa-arrow-up" aria-hidden="true"></i> Money out</button>
        <button type="button" class="btn-tb" wire:click="openMoney('transfer')"><i class="fas fa-right-left" aria-hidden="true"></i> Transfer</button>
      @endcan
    </x-slot:actions>
  </x-ui.page-header>

  <div class="tb-filter-bar tb-toolbar">
    <div class="fn-presets" role="group" aria-label="Period">
      @foreach($presets as $key => $label)
        <button type="button" @class(['fn-preset', 'is-on' => $range === $key]) wire:click="$set('range', '{{ $key }}')" aria-pressed="{{ $range === $key ? 'true' : 'false' }}">{{ $label }}</button>
      @endforeach
    </div>
    <div wire:loading.flex wire:target="range" class="muted tb-small tb-flex" style="gap:6px;"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>
    <div class="tb-toolbar-actions">
      <x-ui.link :href="route('admin.finance.statements', ['report' => 'income'])" class="btn-tb btn-tb-ghost btn-tb-sm"><i class="fas fa-file-lines" aria-hidden="true"></i> Statements</x-ui.link>
    </div>
  </div>

  @if(array_sum($this->pending) > 0)
    <div class="fn-note is-warn fn-health">
      <i class="fas fa-book-medical" aria-hidden="true"></i>
      <div>
        <b>{{ number_format(array_sum($this->pending)) }} records from before your books started aren't in them yet.</b>
        <span class="muted">
          {{ collect(['invoices' => 'invoices', 'payments' => 'payments', 'card_records' => 'card movements', 'insurance' => 'insurer float entries', 'stock' => 'stock movements'])
              ->filter(fn ($l, $k) => $this->pending[$k] > 0)->map(fn ($l, $k) => $this->pending[$k].' '.$l)->implode(', ') }}.
          Until they are, balances only count what has happened since.
        </span>
      </div>
      @can('finance.manage')
        <button type="button" class="btn-tb btn-tb-primary btn-tb-sm" wire:click="catchUp" wire:loading.attr="disabled" wire:target="catchUp">
          <span wire:loading.remove wire:target="catchUp">Bring them in</span>
          <span wire:loading wire:target="catchUp"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Posting…</span>
        </button>
      @endcan
    </div>
  @endif

  <div class="dash-root" wire:loading.class.delay="tb-loading" wire:target="range,catchUp">
    <div class="tb-stats-grid">
      <x-dash.stat icon="fa-wallet" :value="\App\Support\HospitalSettings::money($f['cash'])" label="Cash in your books" sub="Today, across every book" />
      <x-dash.stat icon="fa-arrow-trend-up" tone="ok" :value="\App\Support\HospitalSettings::money($income)" label="Income" :sub="$presets[$range]" />
      <x-dash.stat icon="fa-arrow-trend-down" tone="warn" :value="\App\Support\HospitalSettings::money($spent)" label="Expenses" :sub="'Including the cost of drugs sold'" />
      <x-dash.stat icon="fa-scale-balanced" :tone="(float) $s['totals']['net_profit'] < 0 ? 'bad' : 'ok'"
        :value="\App\Support\HospitalSettings::money($s['totals']['net_profit'])" :label="(float) $s['totals']['net_profit'] < 0 ? 'Net loss' : 'Net profit'"
        :sub="$s['margin'] !== null ? $s['margin'].'% of income' : $presets[$range]" :href="route('admin.finance.statements', ['report' => 'income', 'range' => $range])" />
    </div>

    <div class="fn-grid">
      <x-dash.section title="Your books" icon="fa-book" :href="route('admin.finance.accounts')" view-label="Chart of accounts">
        <div class="fn-books">
          @forelse($f['books'] as $row)
            <x-ui.link :href="route('admin.finance.accounts.show', $row['account'])" class="fn-book" wire:key="bk-{{ $row['account']->id }}">
              <i class="fas {{ match ($row['account']->subtype) { 'bank' => 'fa-building-columns', 'mobile_money' => 'fa-mobile-screen', default => 'fa-money-bill-wave' } }}" aria-hidden="true"></i>
              <span class="fn-book-name">{{ $row['account']->name }}</span>
              <span @class(['fn-book-bal', 'mono', 'is-neg' => (float) $row['balance'] < 0])>{{ \App\Support\HospitalSettings::money($row['balance']) }}</span>
            </x-ui.link>
          @empty
            <x-ui.empty compact icon="fa-book" noun="money books" />
          @endforelse
        </div>
      </x-dash.section>

      <x-dash.section title="Owed and owing" icon="fa-handshake">
        <div class="fn-kv">
          <div><span>Owed to you — unpaid bills and insurers</span><b class="mono">{{ \App\Support\HospitalSettings::money($f['receivables']) }}</b></div>
          <div><span>You owe suppliers</span><b class="mono">{{ \App\Support\HospitalSettings::money($f['payables']) }}</b></div>
          <div><span>Held for patients and insurers (cards, floats)</span><b class="mono">{{ \App\Support\HospitalSettings::money($f['deposits']) }}</b></div>
          <div><span>Tax collected, not yet paid over</span><b class="mono">{{ \App\Support\HospitalSettings::money($f['tax']) }}</b></div>
          <div><span>Stock on the shelf, at cost</span><b class="mono">{{ \App\Support\HospitalSettings::money($f['inventory']) }}</b></div>
        </div>
      </x-dash.section>
    </div>

    <div class="fn-grid">
      <x-dash.section title="Six months: earned and spent" icon="fa-chart-column">
        @php($peak = max(1, ...array_values(array_map(fn ($m) => max((float) $m['income'], (float) $m['expenses']), $f['trend']))))
        <div class="fn-trend" role="img" aria-label="Income and expenses over the last six months">
          @foreach($f['trend'] as $month => $m)
            <div class="fn-trend-col" title="{{ $month }}: income {{ \App\Support\HospitalSettings::money($m['income']) }}, expenses {{ \App\Support\HospitalSettings::money($m['expenses']) }}">
              <div class="fn-trend-bars">
                <span class="fn-trend-bar is-in" style="height:{{ round((float) $m['income'] / $peak * 100) }}%"></span>
                <span class="fn-trend-bar is-out" style="height:{{ round((float) $m['expenses'] / $peak * 100) }}%"></span>
              </div>
              <span class="fn-trend-label">{{ $month }}</span>
            </div>
          @endforeach
        </div>
        <div class="fn-legend"><span class="is-in">Income</span><span class="is-out">Expenses</span></div>
      </x-dash.section>

      <x-dash.section title="Where the money went" icon="fa-chart-pie">
        <x-dash.bars :data="$f['top_expenses']" money />
      </x-dash.section>
    </div>

    <x-dash.section title="Latest entries" icon="fa-book" :href="route('admin.finance.journal')" view-label="Journal">
      @if($f['recent']->isEmpty())
        <x-ui.empty compact icon="fa-book" noun="entries" />
      @else
        <div class="tb-table-wrap"><table class="tb-table">
          <tbody>
            @foreach($f['recent'] as $entry)
              <tr wire:key="rc-{{ $entry->id }}">
                <td class="muted tb-nowrap">{{ $entry->date->format('d M') }}</td>
                <td><button type="button" class="tb-rowbtn" wire:click="peekEntry({{ $entry->id }})">{{ $entry->memo }}</button></td>
                <td><x-ui.badge :tone="$entry->kind->badge()">{{ $entry->kind->label() }}</x-ui.badge></td>
                <td class="tb-text-right"><x-ui.money :amount="$entry->total" /></td>
              </tr>
            @endforeach
          </tbody>
        </table></div>
      @endif
    </x-dash.section>
  </div>

  @include('livewire.finance.partials.money-form')
  @include('livewire.finance.partials.entry-peek')
</div>
