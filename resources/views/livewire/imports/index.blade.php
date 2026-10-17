<div>
  <x-ui.page-header title="Import data">
    <x-slot:explain>
      Bring in what you kept before, from Excel or a CSV file: patients first, then your price list, stock and the appointments already booked.
      Nothing is saved until you have seen every row checked. An import can be undone for {{ \App\Models\ImportBatch::UNDO_HOURS }} hours, as long as nothing has happened to what it added.
    </x-slot:explain>
  </x-ui.page-header>

  <div class="tb-card tb-mb-4">
    <div class="tb-card-header"><span class="tb-card-title"><i class="fas fa-file-import" aria-hidden="true"></i> Start an import</span></div>
    <form wire:submit="upload" class="tb-card-body">
      <fieldset class="imp-kinds">
        <legend class="sr-only">What are you importing?</legend>
        @foreach($kinds as $k)
          <div wire:key="kind-{{ $k->value }}" @class(['imp-kind', 'is-on' => $kind === $k->value])>
            <label class="imp-kind-pick">
              <input type="radio" wire:model.live="kind" value="{{ $k->value }}" class="imp-kind-radio">
              <i class="fas {{ $k->icon() }} imp-kind-icon" aria-hidden="true"></i>
              <span class="imp-kind-words">
                <span class="tb-fw-600">{{ $k->label() }}</span>
                <span class="tb-xs muted">{{ $k->hint() }}</span>
              </span>
            </label>
            <button type="button" class="btn-tb btn-tb-sm" wire:click="template('{{ $k->value }}')" wire:loading.attr="disabled" wire:target="template('{{ $k->value }}')" title="Download the {{ strtolower($k->label()) }} template">
              <i class="fas fa-file-excel" aria-hidden="true"></i> Template
            </button>
          </div>
        @endforeach
      </fieldset>

      <x-ui.drop-file name="file" :has="$file !== null" accept=".xlsx,.csv,.xls,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
        label="Your file" idle="Drop your Excel or CSV file here, or click to choose it"
        hint="An Excel workbook (.xlsx) or CSV, up to 10 MB. The first row must be the headings. Your own columns are fine; you match them next.">
        <x-slot:preview><i class="fas fa-file-excel" aria-hidden="true"></i> {{ $file?->getClientOriginalName() }}</x-slot:preview>
      </x-ui.drop-file>

      <div class="imp-actions">
        <button type="submit" class="btn-tb btn-tb-primary btn-tb-sm" wire:loading.attr="disabled" wire:target="upload,file" @disabled($file === null)>
          <i class="fas fa-arrow-right" aria-hidden="true"></i> Read the file
        </button>
        <span class="tb-xs muted">Next you match the columns, then every row is checked. Nothing is saved yet.</span>
      </div>
    </form>
  </div>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="Search file name…" aria-label="Search imports">
    </div>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading" wire:target="search,gotoPage,previousPage,nextPage,perPage"><div class="tb-table-wrap"><table class="tb-table">
    <caption class="sr-only">Import log</caption>
    <thead><tr><th class="tb-serial">#</th><th>File</th><th>Kind</th><th>When</th><th>By</th><th>Rows</th><th>Result</th><th>Status</th></tr></thead>
    <tbody>
      @forelse($rows as $b)
        <tr wire:key="import-{{ $b->id }}">
          <x-ui.serial :rows="$rows" :loop="$loop" />
          <td class="tb-fw-500"><x-ui.link :href="route('admin.imports.show', $b)">{{ $b->file_name }}</x-ui.link></td>
          <td class="tb-small">{{ $b->kind->label() }}</td>
          <td class="tb-small tb-nowrap">{{ $b->created_at->format('d M Y H:i') }}</td>
          <td class="tb-small">{{ $b->creator?->name ?? '-' }}</td>
          <td class="tb-small">{{ number_format($b->total_rows) }}</td>
          <td class="tb-small">
            @if($b->finished_at)
              {{ number_format($b->added_count) }} added
              @if($b->updated_count) · {{ number_format($b->updated_count) }} updated @endif
              @if($b->skipped_count) · {{ number_format($b->skipped_count) }} left out @endif
              @if($b->failed_count) · {{ number_format($b->failed_count) }} not saved @endif
              @if($b->undone_at)<div class="tb-xs muted">{{ number_format($b->undone_count) }} undone by {{ $b->undoer?->name ?? 'someone' }}, {{ $b->undone_at->format('d M Y H:i') }}</div>@endif
            @else
              <span class="muted">Not saved yet</span>
            @endif
          </td>
          <td><x-ui.badge :tone="$b->status->tone()">{{ $b->status->label() }}</x-ui.badge></td>
        </tr>
      @empty
        <tr><td colspan="8"><x-ui.empty icon="fa-file-import" noun="imports" :filtered="$filtered" wire:click="$set('search', '')" /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  {{ $rows->links(data: $this->paginationData()) }}
</div>
