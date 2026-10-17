<div class="tb-card">
  <div class="tb-card-header">
    <span class="tb-card-title">Who opened this record</span>
    <x-ui.link :href="route('admin.audit.index', ['view' => 'opens', 'patient' => $this->patient->id])" class="btn-tb btn-tb-sm">
      <i class="fas fa-clipboard-list" aria-hidden="true"></i> Audit log
    </x-ui.link>
  </div>
  @if($rows->total() === 0)
    <div class="tb-card-body"><x-ui.empty icon="fa-user-shield" compact message="Nobody has opened this record since the log began." /></div>
  @else
    <div class="tb-table-wrap">
      <table class="tb-table">
        <caption class="sr-only">Who opened or printed this patient's record</caption>
        <thead><tr>
          <th scope="col" class="tb-serial">#</th><th scope="col">When</th><th scope="col">Who</th>
          <th scope="col">What</th><th scope="col">On</th>
        </tr></thead>
        <tbody>
          @foreach($rows as $a)
            <tr wire:key="acc-{{ $a->id }}">
              <x-ui.serial :rows="$rows" :loop="$loop" />
              <td class="tb-nowrap">{{ $a->created_at?->format('d M Y H:i') }}</td>
              <td>{{ $a->user?->name ?? '—' }}@if($a->user?->role)<span class="tb-row-sub">{{ \Illuminate\Support\Str::headline($a->user->role) }}</span>@endif</td>
              <td><x-ui.badge :tone="$a->action === 'printed' ? 'info' : 'neutral'">{{ $a->actionLabel() }}</x-ui.badge>@if($a->context) <span class="muted tb-xs">{{ $a->context }}</span>@endif</td>
              <td class="muted">{{ $a->channel === 'api' ? 'App' : 'Web' }}@if($a->device) · {{ $a->device }}@endif</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    {{ $rows->links() }}
  @endif
</div>
