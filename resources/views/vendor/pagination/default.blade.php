@if ($paginator->hasPages())
    <nav class="pagination" role="navigation" aria-label="Pagination">
        <p class="pagination-summary">
            Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}
            of {{ $paginator->total() }}
        </p>

        <div class="pagination-links">
            @if ($paginator->onFirstPage())
                <span class="page-link is-disabled" aria-disabled="true">
                    <x-ui.icon name="chevron-left" :size="14" />
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" class="page-link" rel="prev" aria-label="Previous page">
                    <x-ui.icon name="chevron-left" :size="14" />
                </a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="page-dots">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="page-link is-active" aria-current="page">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="page-link">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" class="page-link" rel="next" aria-label="Next page">
                    <x-ui.icon name="chevron-right" :size="14" />
                </a>
            @else
                <span class="page-link is-disabled" aria-disabled="true">
                    <x-ui.icon name="chevron-right" :size="14" />
                </span>
            @endif
        </div>
    </nav>
@endif
