<div>
  <x-ui.page-header title="User manual" :crumbs="['User manual' => null]">
    <x-slot:actions>
      <a class="btn-tb btn-tb-sm" href="{{ route('admin.manual.pdf') }}" target="_blank" rel="noopener"><i class="fas fa-file-pdf" aria-hidden="true"></i> PDF</a>
    </x-slot:actions>
  </x-ui.page-header>

  <div class="manual-grid">
    <nav class="tb-card manual-toc" aria-label="Chapters">
      <ol>
        @foreach($chapters as $c)
          <li><a href="{{ route('admin.manual', $c['slug']) }}" wire:navigate @class(['on' => $c['slug'] === $slug])>{{ $c['title'] }}</a></li>
        @endforeach
      </ol>
    </nav>
    <article class="tb-card manual-body">
      @if($chapter)
        <h2 class="manual-title">{{ $chapter['title'] }}</h2>
        <div class="manual-md">{!! $html !!}</div>
      @else
        <x-ui.empty icon="fa-book" message="The manual is not installed on this server." />
      @endif
    </article>
  </div>
</div>
