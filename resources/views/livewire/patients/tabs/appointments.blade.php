@php($d = fn ($iso, $f = 'd M Y H:i') => $iso ? \Illuminate\Support\Carbon::parse($iso)->format($f) : '—')
<div class="tb-stack">
  @foreach(['upcoming' => ['Upcoming', $upcoming, 'No appointments are booked.'], 'past' => ['Past', $past, 'No past appointments.']] as $key => [$title, $rows, $none])
    <div class="tb-card" wire:key="appt-{{ $key }}">
      <div class="tb-card-header"><span class="tb-card-title">{{ $title }}</span><span class="muted tb-small">{{ number_format($rows->total()) }}</span></div>
      @if($rows->total() === 0)
        <div class="tb-card-body"><x-ui.empty icon="fa-calendar" compact :message="$none" /></div>
      @else
        <div class="tb-table-wrap">
          <table class="tb-table">
            <caption class="sr-only">{{ $title }} appointments</caption>
            <thead><tr><th scope="col" class="tb-serial">#</th><th scope="col">When</th><th scope="col">Doctor</th><th scope="col">Department</th><th scope="col">Reason</th><th scope="col">Status</th></tr></thead>
            <tbody>
              @foreach($rows as $a)
                <tr wire:key="appt-{{ $key }}-{{ $a['uuid'] }}">
                  <x-ui.serial :rows="$rows" :loop="$loop" />
                  <td class="tb-nowrap rowlink"><x-ui.link :href="route('admin.appointments.show', $a['uuid'])">{{ $d($a['scheduled_at']) }}</x-ui.link>@if($a['follow_up']) <span class="muted tb-xs">follow-up</span>@endif</td>
                  <td>{{ $a['doctor'] ?? '—' }}</td>
                  <td>{{ $a['department'] ?? '—' }}</td>
                  <td>{{ \Illuminate\Support\Str::limit($a['reason'] ?? '', 50) ?: '—' }}</td>
                  <td><x-ui.badge :tone="$a['status_badge']">{{ $a['status'] }}</x-ui.badge></td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        {{ $rows->links() }}
      @endif
    </div>
  @endforeach
</div>
