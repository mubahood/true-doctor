@php
  use App\Enums\ImportStatus;
  use App\Models\ImportRow;
  $status = $batch->status;
  $headings = $batch->headings ?? [];
  $byKey = collect($fields)->keyBy('key');
@endphp
<div>
  {{-- While a step is running, ask for the next chunk; removed with it. --}}
  @if($status->isWorking())<span wire:poll.750ms="step" class="sr-only">Working on the import</span>@endif

  <x-ui.page-header :title="'Import: '.$batch->kind->label()" :crumbs="['Import data' => route('admin.imports.index'), $batch->file_name => null]">
    <x-slot:explain>
      Match your columns, let every row be checked, decide what to do with rows that look like records you already have, then import.
      Nothing is saved before you press Import. An import can be undone for {{ \App\Models\ImportBatch::UNDO_HOURS }} hours.
    </x-slot:explain>
    <x-slot:actions>
      @if($status->isDraft() && $status !== ImportStatus::Checking)
        <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm" wire:click="cancel" wire:loading.attr="disabled" wire:target="cancel"><i class="fas fa-xmark" aria-hidden="true"></i> Throw away</button>
      @endif
      @if($status === ImportStatus::Done && $undoBlocker === null)
        <button type="button" class="btn-tb btn-tb-sm" wire:click="askUndo"><i class="fas fa-rotate-left" aria-hidden="true"></i> Undo this import</button>
      @endif
    </x-slot:actions>
  </x-ui.page-header>

  <div class="imp-meta tb-mb-3">
    <x-ui.badge :tone="$status->tone()">{{ $status->label() }}</x-ui.badge>
    <span class="tb-small">{{ number_format($batch->total_rows) }} rows from <span class="tb-fw-500">{{ $batch->file_name }}</span></span>
    <span class="tb-xs muted">Uploaded by {{ $batch->creator?->name ?? 'someone' }}, {{ $batch->created_at->format('d M Y H:i') }}</span>
    @if($batch->finished_at)<span class="tb-xs muted">Imported {{ $batch->finished_at->format('d M Y H:i') }}</span>@endif
    @if($status === ImportStatus::Done && $batch->undoUntil()?->isFuture())<span class="tb-xs muted">Can be undone until {{ $batch->undoUntil()->format('d M H:i') }}</span>@endif
    @if($batch->undone_at)<span class="tb-xs muted">Undone by {{ $batch->undoer?->name ?? 'someone' }}, {{ $batch->undone_at->format('d M Y H:i') }}</span>@endif
  </div>

  {{-- ── 1. Match the columns ─────────────────────────────────────────── --}}
  @if($status === ImportStatus::Mapping)
    <form wire:submit="saveMap" class="tb-card">
      <div class="tb-card-header">
        <span class="tb-card-title"><i class="fas fa-table-columns" aria-hidden="true"></i> Match your columns</span>
        <span class="tb-xs muted">We guessed from your headings. Correct anything that is wrong.</span>
      </div>
      @error('map')<div class="tb-alert tb-alert-error" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i> {{ $message }}</div>@enderror
      <div class="tb-table-wrap"><table class="tb-table">
        <caption class="sr-only">Which of your columns holds what</caption>
        <thead><tr><th>We need</th><th>Your column</th><th>First row of your sheet</th><th>What it is</th></tr></thead>
        <tbody>
          @foreach($fields as $f)
            @php $index = $map[$f->key] ?? ''; @endphp
            <tr wire:key="map-{{ $f->key }}">
              <td class="tb-fw-500 tb-nowrap"><label for="map-{{ $f->key }}">{{ $f->label }}</label>@if($f->required) <span class="muted" title="Needed">*</span>@endif</td>
              <td>
                <select id="map-{{ $f->key }}" wire:model.live="map.{{ $f->key }}" class="tb-select imp-map-select">
                  <option value="">Not in my sheet</option>
                  @foreach($headings as $i => $h)<option value="{{ $i }}">{{ $h !== '' ? $h : 'Column '.($i + 1) }}</option>@endforeach
                </select>
              </td>
              <td class="tb-small">{{ $index !== '' ? \Illuminate\Support\Str::limit((string) ($preview->first()?->cells[(int) $index] ?? ''), 40) : '' }}</td>
              <td class="tb-xs muted">{{ $f->note }}</td>
            </tr>
          @endforeach
        </tbody>
      </table></div>
      <div class="tb-card-body imp-actions">
        <button type="submit" class="btn-tb btn-tb-primary btn-tb-sm" wire:loading.attr="disabled" wire:target="saveMap"><i class="fas fa-list-check" aria-hidden="true"></i> Check every row</button>
        <span class="tb-xs muted">Checking saves nothing. You see the result first.</span>
      </div>
    </form>
  @endif

  {{-- ── 2. Working: checking, importing or undoing ──────────────────── --}}
  @if($status->isWorking())
    <div class="tb-card tb-mb-3"><div class="tb-card-body">
      <x-ui.progress :value="$done" :max="$total" :label="match($status) { ImportStatus::Checking => 'Checking rows', ImportStatus::Importing => 'Importing rows', default => 'Taking back what was added' }" />
      <p class="tb-xs muted imp-note">Keep this page open until it finishes. If you close it, the work pauses and carries on when you come back.</p>
    </div></div>
  @endif

  {{-- ── 3. The rows ─────────────────────────────────────────────────── --}}
  @if($rows !== null)
    @php
      $chips = $status->isDraft()
        ? [ImportRow::READY, ImportRow::DUPLICATE, ImportRow::PROBLEM, ImportRow::PENDING]
        : [ImportRow::ADDED, ImportRow::UPDATED, ImportRow::SKIPPED, ImportRow::PROBLEM, ImportRow::FAILED, ImportRow::UNDONE, ImportRow::KEPT, ImportRow::READY, ImportRow::DUPLICATE];
    @endphp
    <div class="imp-chips tb-mb-3" role="group" aria-label="Show rows">
      <button type="button" wire:click="$set('show', '')" @class(['tb-chip', 'is-on' => $show === '']) aria-pressed="{{ $show === '' ? 'true' : 'false' }}">All <span class="muted">{{ number_format($batch->total_rows) }}</span></button>
      @foreach($chips as $c)
        @if(($counts[$c] ?? 0) > 0)
          <button type="button" wire:key="chip-{{ $c }}" wire:click="$set('show', '{{ $c }}')" @class(['tb-chip', 'is-on' => $show === $c]) aria-pressed="{{ $show === $c ? 'true' : 'false' }}">
            {{ ImportRow::LABELS[$c] }} <span class="muted">{{ number_format($counts[$c]) }}</span>
          </button>
        @endif
      @endforeach
    </div>

    @if($status === ImportStatus::Checked)
      <div class="tb-card tb-mb-3"><div class="tb-card-body imp-actions">
        @php $toSave = ($counts[ImportRow::READY] ?? 0) + ($counts[ImportRow::DUPLICATE] ?? 0); @endphp
        <button type="button" class="btn-tb btn-tb-primary btn-tb-sm" wire:click="startImport" wire:loading.attr="disabled" wire:target="startImport" @disabled($toSave === 0)>
          <i class="fas fa-file-import" aria-hidden="true"></i> Import
        </button>
        <span class="tb-small">
          {{ number_format($counts[ImportRow::READY] ?? 0) }} to add.
          @if(($counts[ImportRow::DUPLICATE] ?? 0) > 0) {{ number_format($counts[ImportRow::DUPLICATE]) }} look like records you have: choose for each below, or for all: @endif
        </span>
        @if(($counts[ImportRow::DUPLICATE] ?? 0) > 0)
          <span class="imp-all">
            @foreach($decisions as $d)
              <button type="button" wire:key="all-{{ $d }}" class="btn-tb btn-tb-sm" wire:click="decideAll('{{ $d }}')" wire:loading.attr="disabled" wire:target="decideAll">{{ ImportRow::DECISIONS[$d] }}</button>
            @endforeach
          </span>
        @endif
        @if(($counts[ImportRow::PROBLEM] ?? 0) > 0)<span class="tb-xs muted">{{ number_format($counts[ImportRow::PROBLEM]) }} with problems will be left out. Fix them in your sheet and import those rows again.</span>@endif
        <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm" wire:click="editMap"><i class="fas fa-table-columns" aria-hidden="true"></i> Change columns</button>
      </div></div>
    @endif

    @if($status === ImportStatus::Done && $undoBlocker !== null && $batch->added_count > 0)
      <p class="tb-xs muted tb-mb-3">{{ $undoBlocker }}</p>
    @endif

    <div class="tb-card"><div class="tb-table-wrap"><table class="tb-table">
      <caption class="sr-only">Rows of the sheet</caption>
      <thead><tr><th class="tb-serial">Row</th><th>From your sheet</th><th>Status</th><th>Details</th></tr></thead>
      <tbody>
        @forelse($rows as $row)
          @php
            $values = $imports->values($batch, $row);
            $summary = collect($fields)->map(fn ($f) => $values[$f->key] ?? '')->filter(fn ($v) => $v !== '')->take(4)->implode(' · ');
          @endphp
          <tr wire:key="row-{{ $row->id }}">
            <td class="tb-serial">{{ $row->line }}</td>
            <td class="tb-small">{{ \Illuminate\Support\Str::limit($summary, 90) ?: '(empty)' }}</td>
            <td class="tb-nowrap"><x-ui.badge :tone="$row->tone()">{{ $row->label() }}</x-ui.badge></td>
            <td class="tb-small">
              @if($row->problems)
                <ul class="imp-problems">@foreach($row->problems as $p)<li>{{ $p }}</li>@endforeach</ul>
              @endif
              @if($row->match_reason)<div>{{ $row->match_reason }}</div>@endif
              @if($row->status === ImportRow::DUPLICATE && $status === ImportStatus::Checked)
                <label class="sr-only" for="decide-{{ $row->id }}">What to do with row {{ $row->line }}</label>
                <select id="decide-{{ $row->id }}" class="tb-select imp-decide" wire:change="decide({{ $row->id }}, $event.target.value)">
                  @foreach($decisions as $d)<option value="{{ $d }}" @selected($row->decision === $d)>{{ ImportRow::DECISIONS[$d] }}</option>@endforeach
                </select>
              @elseif($row->status === ImportRow::DUPLICATE && $row->decision)
                <div class="muted">{{ ImportRow::DECISIONS[$row->decision] ?? '' }}</div>
              @endif
              @if($row->message)<div class="muted">{{ $row->message }}</div>@endif
              @if(($patient = $patients[$row->record_id] ?? null) && $row->record_type === \App\Models\Patient::class)
                <x-ui.link :href="route('admin.patients.show', $patient)">Open {{ $patient->full_name }}</x-ui.link>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="4"><x-ui.empty compact icon="fa-table-list" noun="rows" :filtered="$show !== ''" /></td></tr>
        @endforelse
      </tbody>
    </table></div></div>
    {{ $rows->links() }}
  @endif

  {{-- ── Undo, confirmed ─────────────────────────────────────────────── --}}
  <x-ui.modal show="confirmUndo" size="sm" title="Undo this import?">
    @if($confirmUndo)
      <div class="tb-modal-body">
        <p class="tb-small">
          The {{ number_format($counts[ImportRow::ADDED] ?? 0) }} {{ \Illuminate\Support\Str::plural($batch->kind->noun(), $counts[ImportRow::ADDED] ?? 0) }} this import added will be taken back.
          Any that have been used since (a visit, a bill, a sale, a change) are kept and listed.
          Records it updated keep their changes.
        </p>
      </div>
      <div class="tb-modal-foot">
        <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm" @click="requestClose()">Keep it</button>
        <button type="button" class="btn-tb btn-tb-danger btn-tb-sm" wire:click="undo" wire:loading.attr="disabled" wire:target="undo"><i class="fas fa-rotate-left" aria-hidden="true"></i> Undo the import</button>
      </div>
    @endif
  </x-ui.modal>
</div>
