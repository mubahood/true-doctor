@php($d = $this->data)
@php($m = fn ($v) => \App\Support\HospitalSettings::money($v))
<div>
  <x-ui.page-header title="Financial statements">
    <x-slot:explain>Read live off your journal — every invoice, payment, stock movement and entry you record is in these the moment it is posted. Balances are as at the last day of the period; income and cash flow cover the whole period.</x-slot:explain>
    <x-slot:actions>
      <a href="{{ route('admin.finance.export', ['what' => 'statement'] + $exportQuery) }}" class="btn-tb btn-tb-ghost"><i class="fas fa-file-csv" aria-hidden="true"></i> CSV</a>
      <a href="{{ route('admin.finance.statement-pdf', $exportQuery) }}" target="_blank" rel="noopener" class="btn-tb"><i class="fas fa-file-pdf" aria-hidden="true"></i> PDF</a>
    </x-slot:actions>
  </x-ui.page-header>

  <div class="fn-tabs" role="tablist" aria-label="Statement">
    @foreach($reports as $key => [$label, $icon])
      <button type="button" role="tab" aria-selected="{{ $report === $key ? 'true' : 'false' }}" @class(['fn-tab', 'is-on' => $report === $key]) wire:click="show('{{ $key }}')">
        <i class="fas {{ $icon }}" aria-hidden="true"></i> {{ $label }}
      </button>
    @endforeach
  </div>

  <div class="tb-filter-bar tb-toolbar">
    <div class="fn-presets" role="group" aria-label="Period">
      @foreach($presets as $key => $label)
        <button type="button" class="fn-preset" wire:click="preset('{{ $key }}')">{{ $label }}</button>
      @endforeach
    </div>
    <input type="date" wire:model.live="from" class="tb-input fn-date" aria-label="From" @disabled(in_array($report, ['balance', 'trial'], true))>
    <input type="date" wire:model.live="to" class="tb-input fn-date" aria-label="To">
    @if($report === 'income')
      <select wire:model.live="compare" class="tb-select" aria-label="Compare with">
        <option value="none">No comparison</option>
        <option value="previous">Compare: period before</option>
        <option value="year">Compare: a year earlier</option>
      </select>
    @endif
    <div wire:loading.flex wire:target="from,to,compare,preset,show" class="muted tb-small tb-flex" style="gap:6px;"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</div>
  </div>

  <div class="tb-card fn-statement" wire:loading.class.delay="tb-loading" wire:target="from,to,compare,preset,show">
    <div class="fn-statement-head">
      <div>
        <h2>{{ $reports[$report][0] }}</h2>
        <span class="muted tb-small">{{ $periodLabel }}</span>
      </div>
      @if(isset($d['balanced']))
        <x-ui.badge :tone="$d['balanced'] ? 'success' : 'danger'"><i class="fas {{ $d['balanced'] ? 'fa-check' : 'fa-triangle-exclamation' }}" aria-hidden="true"></i> {{ $d['balanced'] ? 'Balances' : 'Does not balance' }}</x-ui.badge>
      @endif
    </div>

    @switch($report)
      @case('income')
        @php($cmp = $d['compare'] !== null)
        <table class="tb-table fn-st">
          <thead><tr><th></th><th class="tb-text-right">{{ $periodLabel }}</th>@if($cmp)<th class="tb-text-right">{{ $compareLabel }}</th><th class="tb-text-right">Change</th>@endif</tr></thead>
          @foreach([
              ['Income from patient care', $d['revenue'], 'net_revenue', false],
              ['Discounts given', $d['discounts'], null, false],
              ['Cost of drugs and supplies sold', $d['cost_of_sales'], 'gross_profit', true],
              ['Running costs', $d['expenses'], 'operating_profit', true],
              ['Other income', $d['other_income'], 'net_profit', false],
            ] as [$title, $section, $after, $isCost])
            @if($section['rows'] !== [] || $after !== null)
              <tbody>
                @if($section['rows'] !== [])
                  <tr class="fn-st-head"><td colspan="{{ $cmp ? 4 : 2 }}">{{ $title }}</td></tr>
                  @foreach($section['rows'] as $r)
                    <tr wire:key="is-{{ $r['account']->id }}">
                      <td class="fn-st-acct"><x-ui.link :href="route('admin.finance.accounts.show', ['account' => $r['account'], 'from' => $d['from'], 'to' => $d['to']])">{{ $r['account']->name }}</x-ui.link></td>
                      <td class="tb-text-right mono">{{ $m($r['amount']) }}</td>
                      @if($cmp)
                        <td class="tb-text-right mono muted">{{ $m($r['compare'] ?? '0') }}</td>
                        <td class="tb-text-right mono tb-small">@include('livewire.finance.partials.change', ['now' => $r['amount'], 'then' => $r['compare'] ?? '0', 'cost' => $isCost])</td>
                      @endif
                    </tr>
                  @endforeach
                @endif
                @if($after !== null)
                  @php($label = ['net_revenue' => 'Net income from patient care', 'gross_profit' => 'Gross profit', 'operating_profit' => 'Operating profit', 'net_profit' => ((float) $d['totals']['net_profit'] < 0 ? 'Net loss' : 'Net profit')][$after])
                  <tr @class(['fn-st-sub', 'fn-st-final' => $after === 'net_profit'])>
                    <td>{{ $label }}</td>
                    <td class="tb-text-right mono">{{ $m($d['totals'][$after]) }}</td>
                    @if($cmp)
                      <td class="tb-text-right mono muted">{{ $m($d['compare'][$after]) }}</td>
                      <td class="tb-text-right mono tb-small">@include('livewire.finance.partials.change', ['now' => $d['totals'][$after], 'then' => $d['compare'][$after], 'cost' => false])</td>
                    @endif
                  </tr>
                @endif
              </tbody>
            @endif
          @endforeach
        </table>
        @if($d['margin'] !== null)<p class="muted tb-small fn-st-foot">Net margin: {{ $d['margin'] }}% of net income.</p>@endif
        @break

      @case('balance')
        <div class="fn-bs">
          <div>
            <table class="tb-table fn-st">
              @foreach([['Current assets', $d['current_assets']], ['Equipment and other fixed assets', $d['fixed_assets']]] as [$title, $section])
                @if($section['rows'] !== [])
                  <tbody>
                    <tr class="fn-st-head"><td colspan="2">{{ $title }}</td></tr>
                    @foreach($section['rows'] as $r)
                      <tr wire:key="bs-{{ $r['account']->id }}"><td class="fn-st-acct"><x-ui.link :href="route('admin.finance.accounts.show', $r['account'])">{{ $r['account']->name }}</x-ui.link></td><td class="tb-text-right mono">{{ $m($r['amount']) }}</td></tr>
                    @endforeach
                    <tr class="fn-st-sub"><td>Total {{ Str::lower($title) }}</td><td class="tb-text-right mono">{{ $m($section['total']) }}</td></tr>
                  </tbody>
                @endif
              @endforeach
              <tbody><tr class="fn-st-final"><td>Total assets</td><td class="tb-text-right mono">{{ $m($d['assets']) }}</td></tr></tbody>
            </table>
          </div>
          <div>
            <table class="tb-table fn-st">
              @foreach([['Owed now', $d['current_liabilities']], ['Long-term loans', $d['long_term_liabilities']]] as [$title, $section])
                @if($section['rows'] !== [])
                  <tbody>
                    <tr class="fn-st-head"><td colspan="2">{{ $title }}</td></tr>
                    @foreach($section['rows'] as $r)
                      <tr wire:key="bl-{{ $r['account']->id }}"><td class="fn-st-acct"><x-ui.link :href="route('admin.finance.accounts.show', $r['account'])">{{ $r['account']->name }}</x-ui.link></td><td class="tb-text-right mono">{{ $m($r['amount']) }}</td></tr>
                    @endforeach
                  </tbody>
                @endif
              @endforeach
              <tbody><tr class="fn-st-sub"><td>Total liabilities</td><td class="tb-text-right mono">{{ $m($d['liabilities']) }}</td></tr></tbody>
              <tbody>
                <tr class="fn-st-head"><td colspan="2">Equity</td></tr>
                @foreach($d['equity']['rows'] as $r)
                  <tr wire:key="be-{{ $r['account']->id }}"><td class="fn-st-acct"><x-ui.link :href="route('admin.finance.accounts.show', $r['account'])">{{ $r['account']->name }}</x-ui.link></td><td class="tb-text-right mono">{{ $m($r['amount']) }}</td></tr>
                @endforeach
                @if((float) $d['earlier_profit'] !== 0.0)
                  <tr><td class="fn-st-acct">Profit kept from earlier years</td><td class="tb-text-right mono">{{ $m($d['earlier_profit']) }}</td></tr>
                @endif
                <tr><td class="fn-st-acct">{{ (float) $d['year_profit'] < 0 ? 'Loss' : 'Profit' }} this year (since {{ \Illuminate\Support\Carbon::parse($d['year_start'])->format('d M Y') }})</td><td class="tb-text-right mono">{{ $m($d['year_profit']) }}</td></tr>
                <tr class="fn-st-sub"><td>Total equity</td><td class="tb-text-right mono">{{ $m($d['equity_total']) }}</td></tr>
              </tbody>
              <tbody><tr class="fn-st-final"><td>Liabilities and equity</td><td class="tb-text-right mono">{{ $m($d['liabilities_and_equity']) }}</td></tr></tbody>
            </table>
          </div>
        </div>
        @break

      @case('trial')
        <table class="tb-table fn-st">
          <thead><tr><th style="width:90px;">Code</th><th>Account</th><th class="tb-text-right">Debit</th><th class="tb-text-right">Credit</th></tr></thead>
          <tbody>
            @forelse($d['rows'] as $r)
              <tr wire:key="tb-{{ $r['account']->id }}">
                <td class="mono muted tb-small">{{ $r['account']->code }}</td>
                <td><x-ui.link :href="route('admin.finance.accounts.show', $r['account'])">{{ $r['account']->name }}</x-ui.link>
                  @if($r['account']->system_key === 'retained_earnings')<span class="muted tb-xs"> · includes profit kept from earlier years</span>@endif</td>
                <td class="tb-text-right mono">@if((float) $r['debit'] > 0){{ $m($r['debit']) }}@endif</td>
                <td class="tb-text-right mono">@if((float) $r['credit'] > 0){{ $m($r['credit']) }}@endif</td>
              </tr>
            @empty
              <tr><td colspan="4"><x-ui.empty compact icon="fa-list-check" message="Nothing has been posted yet." /></td></tr>
            @endforelse
          </tbody>
          <tfoot><tr class="fn-st-final"><td colspan="2">Totals</td><td class="tb-text-right mono">{{ $m($d['debit']) }}</td><td class="tb-text-right mono">{{ $m($d['credit']) }}</td></tr></tfoot>
        </table>
        <p class="muted tb-small fn-st-foot">Income and expenses are this financial year's, from {{ \Illuminate\Support\Carbon::parse($d['year_start'])->format('d M Y') }}; what earlier years made is in retained earnings.</p>
        @break

      @case('cashflow')
        <div class="fn-bs">
          <div>
            <table class="tb-table fn-st">
              <tbody>
                <tr class="fn-st-head"><td colspan="2">Money in</td></tr>
                @forelse($d['inflows'] as $r)
                  <tr><td class="fn-st-acct">{{ $r['label'] }}</td><td class="tb-text-right mono fn-in">{{ $m($r['amount']) }}</td></tr>
                @empty
                  <tr><td colspan="2" class="muted tb-small">None in the period.</td></tr>
                @endforelse
                <tr class="fn-st-sub"><td>Total in</td><td class="tb-text-right mono">{{ $m($d['in']) }}</td></tr>
              </tbody>
              <tbody>
                <tr class="fn-st-head"><td colspan="2">Money out</td></tr>
                @forelse($d['outflows'] as $r)
                  <tr><td class="fn-st-acct">{{ $r['label'] }}</td><td class="tb-text-right mono fn-out">{{ $m($r['amount']) }}</td></tr>
                @empty
                  <tr><td colspan="2" class="muted tb-small">None in the period.</td></tr>
                @endforelse
                <tr class="fn-st-sub"><td>Total out</td><td class="tb-text-right mono">{{ $m($d['out']) }}</td></tr>
              </tbody>
              <tbody><tr class="fn-st-final"><td>Net change in cash</td><td class="tb-text-right mono">{{ $m(bcsub($d['in'], $d['out'], 2)) }}</td></tr></tbody>
            </table>
          </div>
          <div>
            <table class="tb-table fn-st">
              <thead><tr><th>Book</th><th class="tb-text-right">Start</th><th class="tb-text-right">In</th><th class="tb-text-right">Out</th><th class="tb-text-right">End</th></tr></thead>
              <tbody>
                @foreach($d['books'] as $b)
                  <tr wire:key="cf-{{ $b['account']->id }}">
                    <td><x-ui.link :href="route('admin.finance.accounts.show', ['account' => $b['account'], 'from' => $d['from'], 'to' => $d['to']])">{{ $b['account']->name }}</x-ui.link></td>
                    <td class="tb-text-right mono tb-small">{{ $m($b['opening']) }}</td>
                    <td class="tb-text-right mono tb-small fn-in">{{ $m($b['in']) }}</td>
                    <td class="tb-text-right mono tb-small fn-out">{{ $m($b['out']) }}</td>
                    <td class="tb-text-right mono tb-small">{{ $m($b['closing']) }}</td>
                  </tr>
                @endforeach
              </tbody>
              <tfoot><tr class="fn-st-final"><td>All books</td><td class="tb-text-right mono tb-small">{{ $m($d['opening']) }}</td><td class="tb-text-right mono tb-small">{{ $m($d['in']) }}</td><td class="tb-text-right mono tb-small">{{ $m($d['out']) }}</td><td class="tb-text-right mono tb-small">{{ $m($d['closing']) }}</td></tr></tfoot>
            </table>
            <p class="muted tb-xs fn-st-foot">Book totals include transfers between your own books, which cancel out across them.</p>
          </div>
        </div>
        @break
    @endswitch
  </div>
</div>
