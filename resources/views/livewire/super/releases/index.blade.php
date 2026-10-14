@php($building = $releases->contains(fn ($r) => in_array($r->status, ['queued', 'building'], true)))
<div @if($building) wire:poll.4s @endif>
  <x-ui.page-header title="Standalone releases">
    <x-slot:explain>A release is the installation file buyers download: the code at the tip of main, its dependencies, every migration and seeder, the installer and the guide, signed. Before you can publish one, the build installs it from nothing on an empty database and checks it works.</x-slot:explain>
  </x-ui.page-header>

  <div class="tb-card">
    <div class="tb-card-header"><span class="tb-card-title"><i class="fas fa-box-archive" aria-hidden="true"></i> Stage the next release</span></div>
    <div class="tb-card-body">
      <div class="rel-ready">
        @foreach($readiness as $label => $ok)
          <span @class(['rel-check', 'is-ok' => $ok])><i class="fas {{ $ok ? 'fa-circle-check' : 'fa-circle-xmark' }}" aria-hidden="true"></i> {{ $label }}</span>
        @endforeach
      </div>
      <form wire:submit="stage">
        <div class="tb-form-grid">
          <x-ui.field label="Version" for="release-version" name="version" required hint="Suggested: the next one after the last release.">
            <input id="release-version" class="tb-input mono" wire:model="version" placeholder="1.0.0" required>
          </x-ui.field>
        </div>
        <x-ui.field label="What's new" for="release-notes" name="notes" hint="Prefilled from every change since the last release. Buyers see this; edit it as you like.">
          <textarea id="release-notes" class="tb-input" wire:model="notes" rows="7"></textarea>
        </x-ui.field>
        <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="stage" @disabled(in_array(false, $readiness, true))>
          <span wire:loading.remove wire:target="stage"><i class="fas fa-hammer" aria-hidden="true"></i> Build this release</span>
          <span wire:loading wire:target="stage"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Queuing…</span>
        </button>
      </form>
    </div>
  </div>

  <div class="tb-card"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th>Version</th><th>Status</th><th>Progress</th><th>Built from</th><th class="tb-text-right">Files</th><th>Published</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
      @forelse($releases as $release)
        <tr wire:key="release-{{ $release->id }}">
          <td class="mono tb-fw-500">{{ $release->version }}</td>
          <td><x-ui.badge :tone="match ($release->status) { 'published' => 'active', 'staged' => 'info', 'failed' => 'danger', 'yanked' => 'warn', default => 'neutral' }">{{ $release->status === 'staged' ? 'Ready to publish' : ucfirst($release->status) }}</x-ui.badge></td>
          <td class="tb-small">
            @if(in_array($release->status, ['queued', 'building'], true))<i class="fas fa-spinner fa-spin muted" aria-hidden="true"></i>@endif
            <span class="muted">{{ $release->progress }}</span>
            @if($release->failure)<div class="tb-field-error">{{ $release->failure }}</div>@endif
          </td>
          <td class="mono tb-xs muted">{{ $release->commit ? substr($release->commit, 0, 7) : '—' }}@if($release->sha256)<div title="SHA-256 {{ $release->sha256 }}">sha {{ substr($release->sha256, 0, 10) }}…</div>@endif</td>
          <td class="mono tb-text-right">{{ $release->file_count ? number_format($release->file_count) : '—' }}</td>
          <td class="tb-small">{{ $release->published_at?->format('d M Y H:i') ?? '—' }}</td>
          <td class="tb-text-right tb-nowrap">
            @if($release->archive_path && in_array($release->status, ['staged', 'published', 'yanked'], true))
              <a href="{{ route('super.releases.download', $release) }}" class="btn-tb btn-tb-ghost btn-tb-sm" title="Download to inspect"><i class="fas fa-download" aria-hidden="true"></i></a>
            @endif
            @if($release->status === 'staged' && $release->clean_install_passed_at)
              <button type="button" class="btn-tb btn-tb-primary btn-tb-sm" wire:click="publish({{ $release->id }})" wire:confirm="Publish {{ $release->version }}? Buyers whose updates cover today can download it at once.">Publish</button>
            @elseif($release->status === 'published')
              <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm" wire:click="yank({{ $release->id }})" wire:confirm="Withdraw {{ $release->version }} from downloads? Installations already on it are not affected.">Withdraw</button>
            @elseif($release->status === 'failed')
              <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm" wire:click="retry({{ $release->id }})">Build again</button>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="7"><x-ui.empty icon="fa-box-archive" noun="releases" message="No release yet. Stage the first one above." /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
</div>
