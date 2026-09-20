@if ($paginator->hasPages())
    <nav class="pagination" role="navigation" aria-label="Pagination">
        {{-- Prev --}}
        @if ($paginator->onFirstPage())
            <span class="pg-link disabled" aria-disabled="true" aria-label="Previous"><i class="fas fa-chevron-left"></i></span>
        @else
            <a class="pg-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous"><i class="fas fa-chevron-left"></i></a>
        @endif

        {{-- Page windows --}}
        @php
            $current = $paginator->currentPage();
            $last    = $paginator->lastPage();
            $window  = 2; // pages each side of current
            $show    = collect(range(1, $last))->filter(
                fn ($p) => $p === 1 || $p === $last || abs($p - $current) <= $window
            );
            $prev = null;
        @endphp

        @foreach ($show as $page)
            @if ($prev !== null && $page - $prev > 1)
                <span class="pg-gap">&hellip;</span>
            @endif
            @if ($page === $current)
                <span class="pg-link active" aria-current="page">{{ $page }}</span>
            @else
                <a class="pg-link" href="{{ $paginator->url($page) }}">{{ $page }}</a>
            @endif
            @php $prev = $page; @endphp
        @endforeach

        {{-- Next --}}
        @if ($paginator->hasMorePages())
            <a class="pg-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next"><i class="fas fa-chevron-right"></i></a>
        @else
            <span class="pg-link disabled" aria-disabled="true" aria-label="Next"><i class="fas fa-chevron-right"></i></span>
        @endif
    </nav>
@endif
