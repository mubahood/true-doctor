<div>
  <x-ui.page-header title="Diagnoses to code" :crumbs="['Dashboard' => route('admin.dashboard'), 'Diagnoses to code' => null]">
    <x-slot:explain>
      Visits whose diagnosis was written in words but not coded. The Ministry reports count coded diagnoses only,
      so each one coded here is counted. Choose the code that fits; nothing is coded on its own.
    </x-slot:explain>
  </x-ui.page-header>

  <div class="tb-card" wire:loading.class="tb-loading"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th>Visit</th><th>Patient</th><th>Written diagnosis</th><th>Codes that may fit</th></tr></thead>
    <tbody>
      @forelse($visits as $v)
        <tr wire:key="v-{{ $v->id }}">
          <td class="tb-nowrap tb-small"><span class="mono">{{ $v->visit_no }}</span><div class="muted tb-xs">{{ $v->created_at?->format('d M Y') }}</div></td>
          <td class="tb-small">{{ $v->patient?->full_name }}</td>
          <td class="tb-small">{{ \Illuminate\Support\Str::limit($v->diagnosis, 140) }}</td>
          <td>
            @forelse($suggestions[$v->id] ?? [] as $s)
              <button type="button" class="dx-option" wire:click="code({{ $v->id }}, '{{ $s->code }}')" wire:loading.attr="disabled" wire:target="code"><span class="mono">{{ $s->code }}</span> {{ $s->title }}</button>
            @empty
              <a href="{{ route('admin.visits.show', $v) }}" wire:navigate class="tb-small">Open the visit to code it</a>
            @endforelse
          </td>
        </tr>
      @empty
        <tr><td colspan="4"><x-ui.empty icon="fa-notes-medical" message="Every written diagnosis is coded." /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  <div class="tb-mt-3">{{ $visits->links() }}</div>
</div>
