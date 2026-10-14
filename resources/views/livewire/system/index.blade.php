@php($st = $this->state)
@php($until = $licence->updatesUntil())
<div @if($busy) wire:poll.3s @endif>
  <x-ui.page-header title="Licence & updates">
    <x-slot:explain>Your own copy of True-Doctor: the release it runs, what your licence covers, and the health of the things that keep it running on this server. Updates and backups run in the background through the scheduler, so this page can be left at any time.</x-slot:explain>
  </x-ui.page-header>

  {{-- ── What is running ─────────────────────────────────────────── --}}
  <div class="tb-stats-grid">
    <x-dash.stat icon="fa-code-branch" :value="'True-Doctor '.$version" label="Release"
      :sub="isset($installed['installed_at']) ? 'Installed '.\Illuminate\Support\Carbon::parse($installed['installed_at'])->format('d M Y').(isset($installed['updated_at']) ? ' · updated '.\Illuminate\Support\Carbon::parse($installed['updated_at'])->format('d M Y') : '') : null" />
    <x-dash.stat icon="fa-certificate" :tone="$licence->isValid() ? 'ok' : 'warn'"
      :value="$licence->isValid() ? 'Valid' : 'Missing or invalid'" label="Licence"
      :sub="$licence->isValid() ? $licence->customer() : 'Patient care is unaffected'" />
    <x-dash.stat icon="fa-calendar-check" :tone="$until && $until->copy()->endOfDay()->isPast() ? 'warn' : 'ok'"
      :value="$until ? $until->format('d M Y') : '—'" label="Updates included until"
      :sub="$until && $until->copy()->endOfDay()->isPast() ? 'Renew in the buyer portal' : 'New releases up to this date'" />
    <x-dash.stat icon="fa-clock" :tone="$heartbeat && $heartbeat->gt(now()->subMinutes(5)) ? 'ok' : 'bad'"
      :value="$heartbeat ? $heartbeat->diffForHumans() : 'Not running'" label="Scheduler"
      :sub="$heartbeat ? 'Reminders, mail and updates run' : 'Add the cron job — see below'" />
  </div>

  @if($licence->isValid() && ! $hostMatches)
    <div class="fn-note is-warn tb-mb-3"><i class="fas fa-globe" aria-hidden="true"></i>
      This server is reached at <b>{{ request()->getHost() }}</b>, which is not the address on your licence. Change the licensed address in the buyer portal and replace <code>licence.json</code>; nothing stops working meanwhile.</div>
  @endif

  {{-- ── An update or backup in progress, or the last one ───────────── --}}
  @if(in_array($st['status'] ?? 'idle', ['queued', 'running', 'succeeded', 'failed'], true))
    <div class="tb-card sys-state is-{{ $st['status'] }}" role="status">
      <div class="tb-card-header">
        <span class="tb-card-title">
          @switch($st['status'])
            @case('queued') <i class="fas fa-hourglass-half" aria-hidden="true"></i> Waiting to start @break
            @case('running') <i class="fas fa-spinner fa-spin" aria-hidden="true"></i> {{ ($st['action'] ?? '') === 'backup' ? 'Backing up' : 'Updating' }} @break
            @case('succeeded') <i class="fas fa-circle-check" aria-hidden="true"></i> Finished @break
            @case('failed') <i class="fas fa-triangle-exclamation" aria-hidden="true"></i> Stopped @break
          @endswitch
        </span>
        @isset($st['updated_at'])<span class="muted tb-small">{{ \Illuminate\Support\Carbon::parse($st['updated_at'])->diffForHumans() }}</span>@endisset
      </div>
      <div class="tb-card-body">
        <p class="tb-fw-500">{{ $st['message'] ?? '' }}</p>
        @if(($st['status'] ?? null) === 'failed' && ($st['action'] ?? null) === 'update')
          <p class="muted tb-small">Nothing was lost: the previous release and its database were put back, and the site kept running on them. The log below says where it stopped.</p>
        @endif
        @if(! empty($st['log']))
          <pre class="sys-log">{{ implode("\n", $st['log']) }}</pre>
        @endif
        @if(($st['status'] ?? null) === 'queued' && ! ($heartbeat && $heartbeat->gt(now()->subMinutes(5))))
          <p class="tb-field-error">The scheduler is not running, so this will not start. Add the cron job below, or run <code>php artisan standalone:system-requests</code> on the server.</p>
        @endif
      </div>
    </div>
  @endif

  <div class="fn-grid">
    {{-- ── Updates ────────────────────────────────────────────────── --}}
    <x-dash.section title="Updates" icon="fa-cloud-arrow-down">
      <div class="tb-card-body">
        @if($available)
          <div class="sys-available">
            <b>True-Doctor {{ $available['version'] }} is available</b>
            @if(! empty($available['published_at']))<span class="muted tb-small"> · released {{ \Illuminate\Support\Carbon::parse($available['published_at'])->format('d M Y') }}</span>@endif
            @if(! empty($available['notes']))<div class="sys-notes">{!! \Illuminate\Support\Str::markdown((string) $available['notes'], ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>@endif
            <button type="button" class="btn-tb btn-tb-primary tb-mt-3" wire:click="installOnline" @disabled($busy)
                    wire:confirm="Back up and update to {{ $available['version'] }}? The site goes into maintenance mode for a few minutes.">
              <i class="fas fa-download" aria-hidden="true"></i> Back up and update
            </button>
          </div>
        @else
          <p class="muted tb-small">Updates are checked daily. Your licence includes every release made up to {{ $until?->format('d M Y') ?? 'its update date' }}.</p>
        @endif
        <div class="tb-flex tb-mt-3" style="gap:8px;">
          <button type="button" class="btn-tb" wire:click="checkNow" wire:loading.attr="disabled" wire:target="checkNow">
            <span wire:loading.remove wire:target="checkNow"><i class="fas fa-rotate" aria-hidden="true"></i> Check now</span>
            <span wire:loading wire:target="checkNow"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Checking…</span>
          </button>
        </div>
        @if($checkError)<p class="tb-field-error tb-mt-2">{{ $checkError }}</p>@endif

        <div class="wz-divider" role="separator"></div>
        <span class="tb-label">No internet on this server?</span>
        <p class="muted tb-small">Download the update from your buyer portal on any computer, then upload it here. Its signature is checked before anything changes.</p>
        {{-- A plain upload: a release is larger than Livewire's temporary uploads accept. --}}
        <form method="POST" action="{{ route('admin.system.upload') }}" enctype="multipart/form-data" class="tb-flex" style="gap:8px;flex-wrap:wrap;"
              x-data="{ busy: false }" x-on:submit="busy = true">
          @csrf
          <input type="file" name="package" accept=".zip,application/zip" required class="tb-input" style="max-width:320px;">
          <button type="submit" class="btn-tb" :disabled="busy" @disabled($busy)>
            <span x-show="! busy"><i class="fas fa-upload" aria-hidden="true"></i> Upload and update</span>
            <span x-show="busy" x-cloak><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Uploading and checking…</span>
          </button>
        </form>
        <p class="muted tb-xs tb-mt-2">This server accepts uploads up to {{ $uploadLimit }}. For a larger file, put it in <code>true_doctor_app/storage/app/private/updates/</code> with cPanel File Manager; it appears below.</p>

        @foreach($this->waitingFiles as $file)
          <div class="sys-file" wire:key="wf-{{ $file['name'] }}">
            <i class="fas fa-file-zipper" aria-hidden="true"></i>
            <span class="mono tb-small">{{ $file['name'] }}</span>
            <span class="muted tb-xs">{{ number_format($file['size'] / 1048576, 1) }} MB</span>
            <button type="button" class="btn-tb btn-tb-sm" wire:click="installFile('{{ $file['name'] }}')" @disabled($busy)
                    wire:confirm="Verify {{ $file['name'] }} and update? The site goes into maintenance mode for a few minutes.">Verify and update</button>
          </div>
        @endforeach
      </div>
    </x-dash.section>

    {{-- ── Backups ────────────────────────────────────────────────── --}}
    <x-dash.section title="Backups" icon="fa-box-archive">
      <div class="tb-card-body">
        <p class="muted tb-small">Every night the database, your settings and every uploaded document are saved into one file; the newest {{ \App\Services\Install\InstallationBackups::KEEP }} are kept. They are on this same server — <b>download one regularly and keep it somewhere else.</b></p>
        @if($backupAge === null || $backupAge > 48 * 3600)
          <p class="tb-field-error">{{ $backupAge === null ? 'No backup has been made yet.' : 'The newest backup is more than two days old.' }}</p>
        @endif
        <button type="button" class="btn-tb tb-mt-2" wire:click="backupNow" @disabled($busy)><i class="fas fa-floppy-disk" aria-hidden="true"></i> Back up now</button>
        @if($this->backups === [])
          <x-ui.empty compact icon="fa-box-archive" message="No backup yet — the first runs tonight, or press Back up now." />
        @else
          <div class="tb-table-wrap tb-mt-3"><table class="tb-table">
            <tbody>
              @foreach($this->backups as $b)
                <tr wire:key="bk-{{ $b['name'] }}">
                  <td class="tb-small">{{ \Illuminate\Support\Carbon::createFromTimestamp($b['created_at'])->format('d M Y H:i') }}</td>
                  <td class="muted tb-small tb-text-right">{{ number_format($b['size'] / 1048576, 1) }} MB</td>
                  <td class="tb-text-right"><a href="{{ route('admin.system.backup', $b['name']) }}" class="btn-tb btn-tb-sm btn-tb-ghost"><i class="fas fa-download" aria-hidden="true"></i> Download</a></td>
                </tr>
              @endforeach
            </tbody>
          </table></div>
        @endif
      </div>
    </x-dash.section>
  </div>

  {{-- ── This server ─────────────────────────────────────────────── --}}
  <x-dash.section title="This server" icon="fa-server">
    <div class="fn-kv">
      <div><span>Licensed to</span><b>{{ $licence->customer() ?? '—' }}</b></div>
      <div><span>Licence</span><b class="mono">{{ $licence->id() ?? '—' }}</b></div>
      <div><span>Emails and jobs waiting</span><b>{{ number_format($queued) }}</b></div>
      <div><span>Free disk space</span><b>{{ $diskFree ? number_format($diskFree / 1073741824, 1).' GB' : '—' }}</b></div>
      <div><span>PHP</span><b>{{ PHP_VERSION }}</b></div>
      <div><span>Scheduler (cron, once a minute)</span><b class="mono tb-small">cd {{ base_path() }} &amp;&amp; php artisan schedule:run &gt;/dev/null 2&gt;&amp;1</b></div>
    </div>
  </x-dash.section>
</div>
