{{-- What has been filed against a piece of work.

     `owner` is the record; `route` is the named route that streams one of its
     files. Shared by every screen that collects them, because a result, a film
     and a signed consent are the same thing wherever they were uploaded. --}}
@if($owner->attachments->isNotEmpty())
  <ul class="tb-files">
    @foreach($owner->attachments as $file)
      <li wire:key="file-{{ $file->id }}">
        <i class="fas {{ $file->isImage() ? 'fa-image' : 'fa-file-lines' }} tb-file-icon" aria-hidden="true"></i>
        <span class="tb-file-what">
          <a href="{{ route($route, [$owner, $file]) }}" target="_blank" rel="noopener">{{ $file->original_name ?: 'File' }}</a>
          <span class="tb-file-meta">
            {{ $file->readableSize() }}
            @if($file->uploader) · {{ $file->uploader->name }} @endif
            · {{ $file->created_at?->diffForHumans() }}
          </span>
        </span>
        @if($mayRemove ?? false)
          <x-ui.icon-button label="Remove {{ $file->original_name }}" icon="fa-xmark"
                            wire:click="removeAttachment({{ $file->id }})"
                            wire:confirm="Remove this file? It cannot be undone." />
        @endif
      </li>
    @endforeach
  </ul>
@else
  <p class="tb-out-none">Nothing attached yet.</p>
@endif
