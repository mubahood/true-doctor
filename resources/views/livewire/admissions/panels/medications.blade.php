<div class="tb-card">
  <div class="tb-card-header">
    <span class="tb-card-title"><i class="fas fa-pills" aria-hidden="true"></i> Medication record ({{ $this->entries->count() }})</span>
  </div>

  @can('manage', \App\Models\Admission::class)
    @if($this->admission->status->isActive())
      <div class="tb-card-body">
        <form wire:submit="add">
          <div class="tb-form-grid">
            <x-ui.field label="Drug" for="mar-drug" name="drug_name" required>
              <input id="mar-drug" type="text" class="tb-input" wire:model="drug_name" maxlength="120" required>
            </x-ui.field>
            <x-ui.field label="Dose" for="mar-dose" name="dose">
              <input id="mar-dose" type="text" class="tb-input" wire:model="dose" maxlength="60" placeholder="1 g">
            </x-ui.field>
            <x-ui.field label="Route" for="mar-route" name="route">
              <input id="mar-route" type="text" class="tb-input" wire:model="route" maxlength="32" placeholder="IV / oral">
            </x-ui.field>
            <x-ui.field label="Outcome" for="mar-status" name="status" required>
              <select id="mar-status" class="tb-select" wire:model="status" required>
                @foreach($this->statuses as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
              </select>
            </x-ui.field>
            <x-ui.field label="Note" for="mar-note" name="note" full>
              <input id="mar-note" type="text" class="tb-input" wire:model="note" maxlength="255">
            </x-ui.field>
          </div>
          <div class="tb-flex tb-flex-end tb-mt-3">
            <button type="submit" class="btn-tb btn-tb-sm btn-tb-primary" wire:loading.attr="disabled" wire:target="add">
              <span wire:loading.remove wire:target="add"><i class="fas fa-plus" aria-hidden="true"></i> Record medication</span>
              <span wire:loading wire:target="add"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
            </button>
          </div>
        </form>
      </div>
    @endif
  @endcan

  <div class="tb-table-wrap">
    <table class="tb-table tb-small">
      <caption class="sr-only">Medication administration record</caption>
      <thead><tr><th>When</th><th>Drug</th><th>Dose / route</th><th>Outcome</th><th>By</th></tr></thead>
      <tbody>
        @forelse($this->entries as $m)
          <tr wire:key="med-{{ $m->id }}">
            <td class="muted tb-nowrap">{{ $m->created_at?->format('d M H:i') }}</td>
            <td class="tb-fw-500">{{ $m->drug_name }}
              @if($m->note)<div class="muted tb-xs">{{ $m->note }}</div>@endif
            </td>
            <td class="muted">{{ $m->dose ?? '—' }} {{ $m->route }}</td>
            <td><x-ui.badge :tone="$m->status->badge()">{{ $m->status->label() }}</x-ui.badge></td>
            <td class="muted">{{ $m->administeredBy?->name ?? '—' }}</td>
          </tr>
        @empty
          <tr><td colspan="5"><x-ui.empty icon="fa-pills" noun="medications recorded" /></td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>
