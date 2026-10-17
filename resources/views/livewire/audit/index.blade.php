<div>
  <x-ui.page-header title="Audit log"
    :crumbs="['Dashboard' => route('admin.dashboard'), 'Audit log' => null]">
    <x-slot:explain>
      Every change to a clinical or money record, with what each field was before and after, and every
      time somebody opened or printed a patient's record. Nothing here can be edited or removed.
      Confidential fields show only that they changed.
    </x-slot:explain>
  </x-ui.page-header>

  <div class="tb-tabs tb-mb-3" role="tablist" aria-label="Audit log">
    <button type="button" role="tab" class="tb-tab {{ $view === 'changes' ? 'on' : '' }}" aria-selected="{{ $view === 'changes' ? 'true' : 'false' }}"
            wire:click="$set('view', 'changes')"><i class="fas fa-pen-to-square" aria-hidden="true"></i> Changes</button>
    <button type="button" role="tab" class="tb-tab {{ $view === 'opens' ? 'on' : '' }}" aria-selected="{{ $view === 'opens' ? 'true' : 'false' }}"
            wire:click="$set('view', 'opens')"><i class="fas fa-eye" aria-hidden="true"></i> Records opened</button>
  </div>

  <div class="tb-filter-bar tb-toolbar">
    @if($view === 'changes')
      <div class="tb-search-wrap">
        <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
        <input type="search" wire:model.live.debounce.400ms="search" class="tb-input"
               placeholder="Record number…" aria-label="Search by record number">
      </div>
      <select wire:model.live="type" class="tb-select" aria-label="Filter by record type">
        <option value="">Every record type</option>
        @foreach($types as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
      </select>
      <select wire:model.live="event" class="tb-select" aria-label="Filter by what happened">
        <option value="">Every change</option>
        @foreach($events as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
      </select>
    @else
      <div class="tb-filter-pick">
        <livewire:ui.select-search resource="patients" name="patient" :selected="$this->filterPatient?->id"
          placeholder="Any patient" :key="'audit-patient-'.$patient" />
      </div>
      <select wire:model.live="action" class="tb-select" aria-label="Filter by action">
        <option value="">Opened or printed</option>
        @foreach($actions as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
      </select>
    @endif

    <div class="tb-filter-pick">
      <livewire:ui.select-search resource="staff" name="user" :selected="$this->filterUser?->id"
        placeholder="Anyone" :key="'audit-user-'.$view.'-'.$user" />
    </div>
    <input type="date" wire:model.live="from" class="tb-input" aria-label="From date">
    <input type="date" wire:model.live="to" class="tb-input" aria-label="To date">

    @if($this->hasFilters())
      <button type="button" class="tb-chip" wire:click="clearFilters"><i class="fas fa-xmark" aria-hidden="true"></i> Clear</button>
    @endif
  </div>

  <div class="tb-card"><div class="tb-table-wrap"><table class="tb-table">
    @if($view === 'changes')
      <caption class="sr-only">Changes to clinical and money records</caption>
      <thead><tr>
        <th class="tb-serial">#</th><th>When</th><th>Record</th><th>What happened</th><th>Fields</th><th>Who</th><th>Through</th>
      </tr></thead>
      <tbody>
        @forelse($rows as $entry)
          <tr wire:key="audit-{{ $entry->id }}">
            <x-ui.serial :rows="$rows" :loop="$loop" />
            <td class="tb-nowrap muted">{{ $entry->created_at?->format('d M Y H:i') }}</td>
            <td class="tb-fw-500">
              <button type="button" class="tb-rowbtn" wire:click="peek({{ $entry->id }})" title="See what changed">
                {{ \App\Services\Audit\AuditTrail::typeLabel($entry->log_name) }} #{{ $entry->subject_id }}
              </button>
            </td>
            <td>
              @if($entry->event)
                <x-ui.badge :tone="match($entry->event) { 'deleted' => 'danger', 'created' => 'success', default => 'neutral' }">{{ $events[$entry->event] ?? ucfirst($entry->event) }}</x-ui.badge>
              @else
                <span class="muted">{{ $entry->description }}</span>
              @endif
            </td>
            <td class="muted tb-small">{{ \App\Services\Audit\AuditTrail::summary($entry) ?: '—' }}</td>
            <td>{{ $entry->user?->name ?? 'System' }}</td>
            <td class="muted">{{ ['api' => 'App', 'web' => 'Web', 'system' => 'System'][$entry->properties?->get('via')] ?? '—' }}</td>
          </tr>
        @empty
          <tr><td colspan="7">
            <x-ui.empty icon="fa-clipboard-list" noun="changes" :filtered="$this->hasFilters()" wire:click="clearFilters" />
          </td></tr>
        @endforelse
      </tbody>
    @else
      <caption class="sr-only">Patient records opened or printed</caption>
      <thead><tr>
        <th class="tb-serial">#</th><th>When</th><th>Patient</th><th>What</th><th>Who</th><th>On</th>
      </tr></thead>
      <tbody>
        @forelse($rows as $a)
          <tr wire:key="open-{{ $a->id }}">
            <x-ui.serial :rows="$rows" :loop="$loop" />
            <td class="tb-nowrap muted">{{ $a->created_at?->format('d M Y H:i') }}</td>
            <td class="tb-fw-500">
              @if($a->patient)
                <x-ui.link :href="route('admin.patients.show', $a->patient)">{{ $a->patient->full_name }}</x-ui.link>
                <span class="tb-row-sub mono">{{ $a->patient->patient_no }}</span>
              @else
                —
              @endif
            </td>
            <td><x-ui.badge :tone="$a->action === 'printed' ? 'info' : 'neutral'">{{ $a->actionLabel() }}</x-ui.badge>@if($a->context) <span class="muted tb-xs">{{ $a->context }}</span>@endif</td>
            <td>{{ $a->user?->name ?? '—' }}</td>
            <td class="muted">{{ $a->channel === 'api' ? 'App' : 'Web' }}@if($a->device) · {{ $a->device }}@endif</td>
          </tr>
        @empty
          <tr><td colspan="6">
            <x-ui.empty icon="fa-eye" noun="records opened" :filtered="$this->hasFilters()" wire:click="clearFilters" />
          </td></tr>
        @endforelse
      </tbody>
    @endif
  </table></div></div>

  {{ $rows->links(data: $this->paginationData()) }}

  <x-ui.modal show="showPeek" size="lg" :title="$this->peeked ? \App\Services\Audit\AuditTrail::typeLabel($this->peeked->log_name).' #'.$this->peeked->subject_id : 'Change'">
    @if($this->peeked)
      @php($e = $this->peeked)
      <div class="tb-modal-body">
        <x-ui.peek-head :heading="($events[$e->event ?? ''] ?? ucfirst((string) $e->description)).' · '.\App\Services\Audit\AuditTrail::typeLabel($e->log_name)"
                        :sub="($e->user?->name ?? 'System').' · '.$e->created_at?->format('j M Y · H:i:s')" />
        <dl class="tb-peek-facts">
          <dt>Through</dt><dd>{{ ['api' => 'The app', 'web' => 'The web', 'system' => 'The system'][$e->properties?->get('via')] ?? '—' }}</dd>
          @if($e->properties?->get('ip'))<dt>From address</dt><dd class="mono">{{ $e->properties->get('ip') }}</dd>@endif
        </dl>
        @php($fields = \App\Services\Audit\AuditTrail::rows($e))
        @if($fields === [])
          <p class="muted tb-small">{{ $e->description }}</p>
        @else
          <div class="tb-table-wrap">
            <table class="tb-table">
              <caption class="sr-only">Each field before and after</caption>
              <thead><tr><th scope="col">Field</th><th scope="col">Before</th><th scope="col">After</th></tr></thead>
              <tbody>
                @foreach($fields as $f)
                  <tr wire:key="f-{{ $e->id }}-{{ $loop->index }}">
                    <th scope="row">{{ $f['field'] }}</th>
                    <td class="muted tb-audit-value">{{ $f['old'] }}</td>
                    <td class="tb-audit-value">{{ $f['new'] }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @endif
      </div>
    @endif
  </x-ui.modal>
</div>
