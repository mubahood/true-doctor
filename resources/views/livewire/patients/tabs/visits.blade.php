@php
  $d = fn ($iso, $f = 'd M Y') => $iso ? \Illuminate\Support\Carbon::parse($iso)->format($f) : '—';
  $money = in_array('money', $this->sections, true);
@endphp
<div class="tb-card">
  <div class="tb-card-header"><span class="tb-card-title">Visits</span><span class="muted tb-small">{{ number_format($rows->total()) }} in all</span></div>
  @if($rows->total() === 0)
    <div class="tb-card-body"><x-ui.empty icon="fa-stethoscope" noun="visits" /></div>
  @else
    <div class="tb-table-wrap" wire:loading.class.delay="tb-loading" wire:target="gotoPage,previousPage,nextPage">
      <table class="tb-table">
        <caption class="sr-only">Visits of this patient</caption>
        <thead>
          <tr>
            <th scope="col" class="tb-serial">#</th>
            <th scope="col">Visit</th>
            <th scope="col">Date</th>
            <th scope="col">Stage</th>
            <th scope="col">Doctor</th>
            <th scope="col">Diagnosis</th>
            @if($money)<th scope="col" class="tb-text-right">Billed</th><th scope="col" class="tb-text-right">Balance</th>@endif
          </tr>
        </thead>
        <tbody>
          @foreach($rows as $v)
            <tr wire:key="pv-{{ $v['uuid'] }}">
              <x-ui.serial :rows="$rows" :loop="$loop" />
              <td class="mono rowlink"><x-ui.link :href="route('admin.visits.show', $v['uuid'])">{{ $v['visit_no'] }}</x-ui.link></td>
              <td class="tb-nowrap">{{ $d($v['date']) }}</td>
              <td><x-ui.badge :tone="$v['state_badge']">{{ $v['state'] }}</x-ui.badge></td>
              <td>{{ $v['doctor'] ?? '—' }}</td>
              <td>{{ \Illuminate\Support\Str::limit($v['diagnosis'] ?? '', 60) ?: '—' }}</td>
              @if($money)
                <td class="tb-text-right"><x-ui.money :amount="$v['billed']" /></td>
                <td class="tb-text-right @if(bccomp($v['balance'], '0', 2) > 0) tb-danger @endif"><x-ui.money :amount="$v['balance']" /></td>
              @endif
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    {{ $rows->links() }}
  @endif
</div>
