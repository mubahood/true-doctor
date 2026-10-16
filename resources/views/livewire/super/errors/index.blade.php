<div>
  <h1 class="sr-only">Errors</h1>

  <div class="tb-filter-bar tb-toolbar">
    <div class="tb-search-wrap">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" wire:model.live.debounce.400ms="search" class="tb-input" placeholder="A reference (AB12CD), a message or a page…" aria-label="Search errors">
    </div>
    <select class="tb-select" wire:model.live="state" aria-label="Which errors">
      <option value="open">Not fixed ({{ $openCount }})</option>
      <option value="resolved">Marked fixed</option>
      <option value="all">All</option>
    </select>
    <select class="tb-select" wire:model.live="source" aria-label="Where">
      <option value="">Everywhere</option>
      <option value="web">Web</option>
      <option value="queue">Queue</option>
      <option value="console">Scheduled and console</option>
      <option value="app">The app</option>
    </select>
  </div>

  <div class="tb-card" wire:loading.class.delay="tb-loading"><div class="tb-table-wrap"><table class="tb-table">
    <thead><tr><th>What</th><th>Where</th><th class="tb-text-right">Times</th><th>Last seen</th><th>Reference</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
      @forelse($errors as $error)
        <tr wire:key="error-{{ $error->id }}">
          <td>
            <div class="tb-fw-500 tb-small">{{ class_basename($error->class) }}</div>
            <div class="muted tb-xs">{{ \Illuminate\Support\Str::limit($error->message, 160) }}</div>
          </td>
          <td class="tb-xs">
            <x-ui.badge tone="neutral">{{ ucfirst($error->source) }}</x-ui.badge>
            @if($error->path)<div class="mono muted">{{ \Illuminate\Support\Str::limit($error->path, 48) }}</div>@endif
            @if($error->file)<div class="mono muted">{{ \Illuminate\Support\Str::limit($error->file, 48) }}@if($error->line):{{ $error->line }}@endif</div>@endif
            @if($error->last_hospital_id)<div class="muted">{{ $hospitals[$error->last_hospital_id] ?? 'Hospital #'.$error->last_hospital_id }}</div>@endif
          </td>
          <td class="tb-text-right tb-fw-500">{{ number_format($error->occurrences) }}</td>
          <td class="tb-small tb-nowrap">{{ $error->last_seen_at->diffForHumans() }}<div class="muted tb-xs">since {{ $error->first_seen_at->format('d M') }}</div></td>
          <td class="mono tb-small">{{ $error->last_ref }}@if($error->app_version)<div class="muted tb-xs">app {{ $error->app_version }}</div>@endif</td>
          <td class="tb-text-right tb-nowrap">
            @if($error->resolved_at)
              <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm" wire:click="reopen({{ $error->id }})">Reopen</button>
            @else
              <button type="button" class="btn-tb btn-tb-ghost btn-tb-sm" wire:click="resolve({{ $error->id }})">Mark fixed</button>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="6"><x-ui.empty icon="fa-circle-check" noun="errors" :filtered="$search !== '' || $source !== '' || $state !== 'open'" wire:click="$set('search', '')" /></td></tr>
      @endforelse
    </tbody>
  </table></div></div>
  {{ $errors->links(data: $this->paginationData()) }}
</div>
