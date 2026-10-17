<div>
  {{-- While an export is being prepared, look again every 10 seconds; added and removed with it. --}}
  @if($pending)<span wire:poll.10s.visible class="sr-only">Checking on your export</span>@endif
  <x-ui.page-header title="Data protection" :crumbs="['Dashboard' => route('admin.dashboard'), 'Data protection' => null]">
    <x-slot:explain>
      What the Data Protection and Privacy Act 2019 asks a clinic to have: a notice for patients, a way to answer their requests,
      a retention schedule, a breach procedure, an impact assessment and registration with the Personal Data Protection Office.
      Your clinic decides what is done with patients' data; these documents are filled in with your details. Have them read by your own adviser.
    </x-slot:explain>
    <x-slot:actions>
      <x-ui.link :href="route('admin.data-requests.index')" class="btn-tb btn-tb-sm">
        <i class="fas fa-user-shield" aria-hidden="true"></i> Data requests
        @if($openRequests > 0)<x-ui.badge tone="warn">{{ $openRequests }} open</x-ui.badge>@endif
      </x-ui.link>
    </x-slot:actions>
  </x-ui.page-header>

  @if($saas && $hospital)
    <div @class(['fn-note', 'tb-mb-3', 'is-warn' => ! $agreementCurrent])>
      <i class="fas fa-file-signature" aria-hidden="true"></i>
      <div class="dp-agree">
        @if($agreementCurrent)
          <span>Data processing agreement version {{ $agreement['version'] }} accepted by {{ $agreement['name'] }} on {{ \Illuminate\Support\Carbon::parse($agreement['accepted_at'])->format('j M Y') }}.</span>
        @else
          <span>
            {{ $agreement ? 'A new version of the data processing agreement needs accepting.' : 'Your clinic has not accepted the data processing agreement yet.' }}
            Read it first (below), then accept it here.
          </span>
          <button type="button" class="btn-tb btn-tb-primary btn-tb-sm" wire:click="acceptAgreement" wire:loading.attr="disabled" wire:target="acceptAgreement">Accept version {{ config('compliance.dpa_version') }}</button>
        @endif
      </div>
    </div>
  @endif

  <div class="tb-card tb-mb-4">
    <div class="tb-card-header"><span class="tb-card-title"><i class="fas fa-file-shield" aria-hidden="true"></i> Your documents</span></div>
    <div class="tb-table-wrap"><table class="tb-table">
      <thead><tr><th class="tb-serial">#</th><th>Document</th><th>What it is for</th><th><span class="sr-only">Open</span></th></tr></thead>
      <tbody>
        @foreach($documents as $key => [$title, $purpose])
          <tr wire:key="doc-{{ $key }}">
            <td class="tb-serial">{{ $loop->iteration }}</td>
            <td class="tb-fw-500">{{ $title }}</td>
            <td class="tb-small muted">{{ $purpose }}</td>
            <td class="tb-text-right tb-nowrap">
              <a href="{{ route('admin.compliance.document', $key) }}" target="_blank" rel="noopener" class="btn-tb btn-tb-sm"><i class="fas fa-file-pdf" aria-hidden="true"></i> Open PDF</a>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table></div>
  </div>

  <div class="tb-card">
    <div class="tb-card-header">
      <span class="tb-card-title"><i class="fas fa-file-zipper" aria-hidden="true"></i> Export all your data</span>
      @if($hospital)
        <button type="button" class="btn-tb btn-tb-primary btn-tb-sm" wire:click="requestExport" wire:loading.attr="disabled" wire:target="requestExport" @disabled($pending)>
          <i class="fas fa-download" aria-hidden="true"></i> Prepare an export
        </button>
      @endif
    </div>
    <div class="tb-card-body">
      <p class="tb-small muted">
        One file with every record your hospital holds, one spreadsheet (CSV) per table with a list of what each column means,
        and every uploaded document and photo. It is prepared in the background; the download link works for {{ config('compliance.export_hours') }} hours,
        only for you and your hospital's administrators, and every download is logged.
      </p>
    </div>
    <div class="tb-table-wrap"><table class="tb-table">
      <thead><tr><th class="tb-serial">#</th><th>Asked for</th><th>By</th><th>Status</th><th>Contents</th><th><span class="sr-only">Download</span></th></tr></thead>
      <tbody>
        @forelse($exports as $export)
          <tr wire:key="export-{{ $export->id }}">
            <td class="tb-serial">{{ $loop->iteration }}</td>
            <td class="tb-nowrap tb-small">{{ $export->created_at?->format('d M Y H:i') }}</td>
            <td class="tb-small">{{ $export->requester?->name ?? '-' }}</td>
            <td class="tb-small">
              <x-ui.badge :tone="$export->status->tone()">{{ $export->status->label() }}</x-ui.badge>
              @if($export->status === \App\Enums\DataExportStatus::Failed && $export->error)
                <div class="muted tb-xs">{{ $export->error }}</div>
              @elseif($export->isDownloadable())
                <div class="muted tb-xs">Until {{ $export->expires_at?->format('d M H:i') }}</div>
              @endif
            </td>
            <td class="tb-small muted">
              @if($export->tables_count !== null)
                {{ number_format((int) $export->rows_count) }} rows in {{ $export->tables_count }} tables, {{ number_format((int) $export->files_count) }} files, {{ \Illuminate\Support\Number::fileSize((int) $export->size_bytes) }}
                @if($export->download_count > 0)<div class="tb-xs">Downloaded {{ $export->download_count }} {{ \Illuminate\Support\Str::plural('time', $export->download_count) }}</div>@endif
              @else
                -
              @endif
            </td>
            <td class="tb-text-right tb-nowrap">
              @if(isset($links[$export->id]))
                <a href="{{ $links[$export->id] }}" class="btn-tb btn-tb-sm" download><i class="fas fa-download" aria-hidden="true"></i> Download</a>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="6"><x-ui.empty compact icon="fa-file-zipper" noun="exports" /></td></tr>
        @endforelse
      </tbody>
    </table></div>
  </div>
</div>
