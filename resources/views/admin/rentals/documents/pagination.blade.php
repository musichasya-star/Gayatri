@if($paginator->hasPages())
    <nav class="rd-pagination rd-no-print" aria-label="Halaman dokumen">
        <p>Menampilkan {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} dari {{ $paginator->total() }} dokumen</p>
        <div class="rd-pagination-links">
            @if($paginator->onFirstPage())<span class="rd-page-link" aria-disabled="true">Sebelumnya</span>@else<a class="rd-page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">Sebelumnya</a>@endif
            @foreach($paginator->getUrlRange(max(1, $paginator->currentPage() - 2), min($paginator->lastPage(), $paginator->currentPage() + 2)) as $page => $url)
                @if($page === $paginator->currentPage())<span class="rd-page-link rd-page-current" aria-current="page">{{ $page }}</span>@else<a class="rd-page-link" href="{{ $url }}" aria-label="Halaman {{ $page }}">{{ $page }}</a>@endif
            @endforeach
            @if($paginator->hasMorePages())<a class="rd-page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">Berikutnya</a>@else<span class="rd-page-link" aria-disabled="true">Berikutnya</span>@endif
        </div>
    </nav>
@endif
