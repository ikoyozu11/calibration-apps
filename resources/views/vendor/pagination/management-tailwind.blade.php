@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="management-pagination">
        <p class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span>
                Showing <span class="font-semibold text-navy">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}</span>
                of <span class="font-semibold text-navy">{{ $paginator->total() }}</span>
            </span>
            <span>
                Page <span class="font-semibold text-navy">{{ $paginator->currentPage() }}</span>
                of <span class="font-semibold text-navy">{{ $paginator->lastPage() }}</span>
            </span>
        </p>

        <div class="flex items-center gap-2">
            @if ($paginator->onFirstPage())
                <span class="management-pagination-disabled" aria-disabled="true">Previous</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('pagination.previous') }}">Previous</a>
            @endif

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('pagination.next') }}">Next</a>
            @else
                <span class="management-pagination-disabled" aria-disabled="true">Next</span>
            @endif
        </div>
    </nav>
@endif
