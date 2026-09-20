{{-- Themed Livewire pagination: AJAX page changes (wire:click), tb-* styling,
     one block for all screen sizes, no scroll-jump. Used by every WithTable
     component via paginationView(). --}}
@php
    $current = $paginator->currentPage();
    $last = $paginator->lastPage();
    $window = 2;
    $pages = collect(range(1, max($last, 1)))->filter(fn ($p) => $p === 1 || $p === $last || abs($p - $current) <= $window);
    $prev = null;
@endphp
<nav class="tb-pagination" role="navigation" aria-label="Pagination" wire:key="pagination-{{ $paginator->getPageName() }}">
  <div class="pg-info">
    @if($paginator->total() > 0)
      Showing <b>{{ $paginator->firstItem() }}</b>–<b>{{ $paginator->lastItem() }}</b> of <b>{{ number_format($paginator->total()) }}</b>
    @else
      No results
    @endif
    @isset($perPageOptions)
      <label class="tb-per-page" style="margin-left:12px;">Per page
        <select wire:model.live="perPage" class="tb-select" aria-label="Rows per page">
          @foreach($perPageOptions as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach
        </select>
      </label>
    @endisset
  </div>
  @if($paginator->hasPages())
  <div class="pg-links">
    <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" @disabled($paginator->onFirstPage()) aria-label="Previous page" rel="prev"><i class="fas fa-chevron-left"></i></button>
    @foreach($pages as $page)
      @if($prev !== null && $page - $prev > 1)<span class="pg-gap" aria-hidden="true">…</span>@endif
      @if($page === $current)
        <span aria-current="page">{{ $page }}</span>
      @else
        <button type="button" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" aria-label="Go to page {{ $page }}">{{ $page }}</button>
      @endif
      @php($prev = $page)
    @endforeach
    <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" @disabled(! $paginator->hasMorePages()) aria-label="Next page" rel="next"><i class="fas fa-chevron-right"></i></button>
  </div>
  @endif
</nav>
