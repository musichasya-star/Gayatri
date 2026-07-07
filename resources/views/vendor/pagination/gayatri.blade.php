@if ($paginator->hasPages())
    <nav class="pagination" role="navigation" aria-label="Pagination Navigation">
        <div class="pagination-links">
            @if ($paginator->onFirstPage())
                <span class="pagination-link is-disabled" aria-disabled="true">Sebelumnya</span>
            @else
                <a class="pagination-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">Sebelumnya</a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="pagination-link is-disabled" aria-disabled="true">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="pagination-link is-active" aria-current="page">{{ $page }}</span>
                        @else
                            <a class="pagination-link" href="{{ $url }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a class="pagination-link" href="{{ $paginator->nextPageUrl() }}" rel="next">Berikutnya</a>
            @else
                <span class="pagination-link is-disabled" aria-disabled="true">Berikutnya</span>
            @endif
        </div>

        <p class="pagination-summary">
            Menampilkan {{ $paginator->firstItem() }} sampai {{ $paginator->lastItem() }} dari {{ $paginator->total() }} data
        </p>
    </nav>
@endif
