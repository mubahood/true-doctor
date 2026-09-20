<div class="tb-card">
  <div class="tb-card-header">
    <span class="tb-card-title"><i class="fas fa-heart-pulse" aria-hidden="true"></i> Vitals rounds ({{ $this->rounds->count() }})</span>
  </div>

  @can('manage', \App\Models\Admission::class)
    @if($this->admission->status->isActive())
      <div class="tb-card-body">
        <form wire:submit="add">
          <div class="tb-form-grid">
            <x-ui.field label="Temperature (°C)" for="vr-temp" name="temperature">
              <input id="vr-temp" type="number" step="0.1" min="25" max="45" class="tb-input" wire:model="temperature">
            </x-ui.field>
            <x-ui.field label="Blood pressure" for="vr-bp" name="blood_pressure" hint="Systolic/diastolic, e.g. 120/80.">
              <input id="vr-bp" type="text" class="tb-input" wire:model="blood_pressure" maxlength="12" placeholder="120/80">
            </x-ui.field>
            <x-ui.field label="Pulse (bpm)" for="vr-pulse" name="pulse">
              <input id="vr-pulse" type="number" min="20" max="300" class="tb-input" wire:model="pulse">
            </x-ui.field>
            <x-ui.field label="Respiratory rate" for="vr-rr" name="respiratory_rate">
              <input id="vr-rr" type="number" min="4" max="80" class="tb-input" wire:model="respiratory_rate">
            </x-ui.field>
            <x-ui.field label="SpO₂ (%)" for="vr-spo2" name="spo2">
              <input id="vr-spo2" type="number" min="50" max="100" class="tb-input" wire:model="spo2">
            </x-ui.field>
            <x-ui.field label="Note" for="vr-note" name="note">
              <input id="vr-note" type="text" class="tb-input" wire:model="note" maxlength="255">
            </x-ui.field>
          </div>
          <div class="tb-flex tb-flex-end tb-mt-3">
            <button type="submit" class="btn-tb btn-tb-sm btn-tb-primary" wire:loading.attr="disabled" wire:target="add">
              <span wire:loading.remove wire:target="add"><i class="fas fa-plus" aria-hidden="true"></i> Record vitals</span>
              <span wire:loading wire:target="add"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
            </button>
          </div>
        </form>
      </div>
    @endif
  @endcan

  <div class="tb-table-wrap">
    <table class="tb-table tb-small">
      <caption class="sr-only">Observation chart</caption>
      <thead><tr><th>When</th><th>Temp</th><th>BP</th><th>Pulse</th><th>Resp</th><th>SpO₂</th><th>By</th></tr></thead>
      <tbody>
        @forelse($this->rounds as $v)
          <tr wire:key="vitals-{{ $v->id }}">
            <td class="muted tb-nowrap">{{ $v->created_at?->format('d M H:i') }}</td>
            <td class="mono">{{ $v->temperature ?? '—' }}</td>
            <td class="mono">{{ $v->blood_pressure ?? '—' }}</td>
            <td class="mono">{{ $v->pulse ?? '—' }}</td>
            <td class="mono">{{ $v->respiratory_rate ?? '—' }}</td>
            <td class="mono">{{ $v->spo2 ?? '—' }}</td>
            <td class="muted">{{ $v->recordedBy?->name ?? '—' }}</td>
          </tr>
        @empty
          <tr><td colspan="7"><x-ui.empty icon="fa-heart-pulse" noun="vitals rounds" /></td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>
