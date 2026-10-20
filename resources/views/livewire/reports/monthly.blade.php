@php($R = \App\Services\Reports\MonthlyReports::class)
@php($period = $this->period)
@php($q = $this->exportQuery())
@php($num = ['money', 'int', 'percent'])
<div>
  <x-ui.page-header title="Monthly reports" :crumbs="['Reports' => route('admin.reports.index'), 'Monthly reports' => null]">
    <x-slot:explain>
      The income book, the expenditure book, the receivables list and the patient register, for a month or any dates.
      Income is the payments taken less the refunds paid, the same figures as the cashier's day. Expenditure is every payment out of your cash, bank and mobile money books, with the running expenses as their own total. Diagnoses and complaints show only to people who may read clinical notes.
      Accounts receivable is what was owed at the end of the period. Each report opens as a PDF and downloads as Excel or CSV.
    </x-slot:explain>
    <x-slot:actions>
      <a class="btn-tb btn-tb-sm" target="_blank" rel="noopener" href="{{ route('admin.reports.monthly.pdf', ['type' => $type, 'view' => $view] + $q) }}"><i class="fas fa-file-pdf" aria-hidden="true"></i> PDF</a>
      <a class="btn-tb btn-tb-sm" href="{{ route('admin.reports.monthly.export', ['type' => $type, 'view' => $view, 'format' => 'xlsx'] + $q) }}"><i class="fas fa-file-excel" aria-hidden="true"></i> Excel</a>
      <a class="btn-tb btn-tb-sm btn-tb-ghost" href="{{ route('admin.reports.monthly.export', ['type' => $type, 'view' => $view, 'format' => 'csv'] + $q) }}"><i class="fas fa-file-csv" aria-hidden="true"></i> CSV</a>
    </x-slot:actions>
  </x-ui.page-header>

  <div class="tb-seg" role="group" aria-label="Report">
    @foreach($types as $key => $label)
      <button type="button" @class(['tb-seg-btn', 'is-on' => $type === $key]) wire:click="$set('type', '{{ $key }}')" @if($type === $key) aria-pressed="true" @endif>{{ $label }}</button>
    @endforeach
  </div>

  <div class="mr-bar">
    @if($custom)
      <x-ui.field label="From" for="mr-from" name="from">
        <input id="mr-from" type="date" wire:model.live="from" class="tb-input">
      </x-ui.field>
      <x-ui.field label="To" for="mr-to" name="to">
        <input id="mr-to" type="date" wire:model.live="to" class="tb-input">
      </x-ui.field>
      <button type="button" class="tb-linkbtn tb-small" wire:click="useMonth">Whole month</button>
    @else
      <x-ui.field label="Month" for="mr-month" name="month">
        <input id="mr-month" type="month" wire:model.live="month" class="tb-input">
      </x-ui.field>
      <button type="button" class="tb-linkbtn tb-small" wire:click="useCustom">Choose dates</button>
    @endif
    <span class="mr-period">{{ $period->label() }}</span>

    <div class="tb-seg tb-ml-auto" role="group" aria-label="View">
      @foreach($views as $key => $label)
        <button type="button" @class(['tb-seg-btn', 'is-on' => $view === $key]) wire:click="show('{{ $key }}')" @if($view === $key) aria-pressed="true" @endif>{{ $label }}</button>
      @endforeach
    </div>
    <div wire:loading.flex wire:target="type,view,month,from,to,show,useMonth,useCustom,gotoPage,previousPage,nextPage,perPage" class="muted tb-small tb-flex">
      <i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Loading
    </div>
  </div>

  @if($view === 'summary')
    @php($summary = $this->summary)
    <div class="tb-stats-grid tb-mb-4">
      @foreach($summary['figures'] as $f)
        <x-dash.stat :value="$R::display($f['value'], $f['type'])" :label="$f['label']" :sub="$f['note'] ?? null"
          :icon="$f['type'] === 'money' ? 'fa-coins' : ($f['type'] === 'percent' ? 'fa-chart-line' : 'fa-hashtag')" />
      @endforeach
    </div>

    <div class="dash-grid cols-2 tops">
      @foreach($summary['tables'] as $table)
        <x-dash.section :title="$table['title']" icon="fa-table">
          <div class="tb-table-wrap"><table class="tb-table">
            <thead><tr>
              @foreach($table['columns'] as $c)
                <th @class(['tb-text-right' => in_array($c['type'], $num, true)])>{{ $c['label'] }}</th>
              @endforeach
            </tr></thead>
            <tbody>
              @forelse($table['rows'] as $row)
                <tr wire:key="mr-{{ $table['key'] }}-{{ $loop->index }}">
                  @foreach($table['columns'] as $c)
                    <td @class(['tb-text-right mono' => in_array($c['type'], $num, true)])>{{ $R::display($row[$c['key']] ?? null, $c['type']) }}</td>
                  @endforeach
                </tr>
              @empty
                <tr><td colspan="{{ count($table['columns']) }}"><x-ui.empty compact icon="fa-table" message="Nothing in this period." /></td></tr>
              @endforelse
            </tbody>
            @if(! empty($table['total']) && $table['rows'] !== [])
              <tfoot><tr class="fn-total">
                @foreach($table['columns'] as $c)
                  <td @class(['tb-text-right mono' => in_array($c['type'], $num, true)])>{{ $R::display($table['total'][$c['key']] ?? null, $c['type'] === 'percent' ? 'text' : $c['type']) }}</td>
                @endforeach
              </tr></tfoot>
            @endif
          </table></div>
          @if(! empty($table['note']))<p class="mr-note">{{ $table['note'] }}</p>@endif
        </x-dash.section>
      @endforeach
    </div>
  @else
    @php($columns = $report->columns())
    <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="type,view,month,from,to,gotoPage,previousPage,nextPage,perPage">
      <div class="tb-table-wrap"><table class="tb-table mr-table">
        <thead><tr>
          <th>#</th>
          @foreach($columns as $c)
            <th @class(['tb-text-right' => in_array($c['type'], $num, true)])>{{ $c['label'] }}</th>
          @endforeach
        </tr></thead>
        <tbody>
          @forelse($rows as $row)
            <tr wire:key="mr-row-{{ $rows->firstItem() + $loop->index }}">
              <td class="muted tb-small">{{ $rows->firstItem() + $loop->index }}</td>
              @foreach($columns as $c)
                @php($shown = $R::display($row[$c['key']] ?? null, $c['type']))
                @if(in_array($c['type'], $num, true))
                  <td class="tb-text-right mono">{{ $shown }}</td>
                @else
                  <td><span class="mr-clip" title="{{ $shown }}">{{ $shown }}</span></td>
                @endif
              @endforeach
            </tr>
          @empty
            <tr><td colspan="{{ count($columns) + 1 }}"><x-ui.empty icon="fa-table" :message="'Nothing for '.$period->label().'.'" /></td></tr>
          @endforelse
        </tbody>
      </table></div>
      @if($rows->total() > 0)
        <div class="mr-totals">
          @foreach($totals as $t)
            <span>{{ $t['label'] }}: <b class="mono">{{ $R::display($t['value'], $t['type']) }}</b></span>
          @endforeach
        </div>
      @endif
    </div>
    {{ $rows->links(data: $this->paginationData()) }}
  @endif
</div>
