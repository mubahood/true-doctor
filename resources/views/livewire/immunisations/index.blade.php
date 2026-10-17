<div>
  <x-ui.page-header title="Immunisations due" :crumbs="['Dashboard' => route('admin.dashboard'), 'Immunisations due' => null]">
    <x-slot:explain>
      Children with a dose of the national schedule due or overdue on the day, overdue first. A dose is due from its date
      for two weeks, then overdue. Open a child to record what was given.
    </x-slot:explain>
  </x-ui.page-header>

  <div class="tb-filter-bar tb-toolbar">
    <label class="sr-only" for="imm-q">Search</label>
    <input id="imm-q" type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Search name or number…">
    <label class="sr-only" for="imm-status">Status</label>
    <select id="imm-status" wire:model.live="status" class="tb-select">
      <option value="">Due and overdue</option>
      <option value="due">Due</option>
      <option value="overdue">Overdue</option>
    </select>
    <label class="sr-only" for="imm-on">On</label>
    <input id="imm-on" type="date" wire:model.live="on" class="tb-input tb-input-slim">
    <span class="muted tb-xs">{{ $rows->total() }} {{ $rows->total() === 1 ? 'child' : 'children' }} · {{ $overdueTotal }} overdue {{ $overdueTotal === 1 ? 'dose' : 'doses' }}</span>
  </div>

  <div class="tb-card" wire:loading.class="tb-loading"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th class="tb-serial">#</th><th>Child</th><th>Age</th><th>Phone</th><th>Doses</th></tr></thead>
    <tbody>
      @forelse($rows as $r)
        <tr wire:key="due-{{ $r['patient']->uuid }}">
          <x-ui.serial :rows="$rows" :loop="$loop" />
          <td>
            <x-ui.link :href="route('admin.patients.show', ['patient' => $r['patient'], 'tab' => 'immunisation'])"><b class="tb-small">{{ $r['patient']->full_name }}</b></x-ui.link>
            <div class="muted tb-xs mono">{{ $r['patient']->patient_no }}</div>
          </td>
          <td class="tb-small tb-nowrap">{{ \App\Support\MotherChild\MotherChild::ageLabel(\App\Support\MotherChild\MotherChild::days($r['patient']->dob, $onDate)) }}</td>
          <td class="tb-small mono tb-nowrap">{{ $r['patient']->phone_1 ?? '—' }}</td>
          <td>
            @foreach($r['due'] as $dose)
              <x-ui.badge :tone="$dose['tone']">{{ $dose['vaccine'] }} {{ $dose['dose'] }} · {{ \Illuminate\Support\Carbon::parse($dose['due_on'])->format('d M') }}</x-ui.badge>
            @endforeach
          </td>
        </tr>
      @empty
        <tr><td colspan="5"><x-ui.empty icon="fa-syringe" :filtered="$search !== '' || $status !== ''" noun="children" :message="$search !== '' || $status !== '' ? null : 'No child has a dose due on this day.'" /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  <div class="tb-mt-3">{{ $rows->links() }}</div>
</div>
