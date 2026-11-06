@if ($paginator->hasPages())
<nav class="actions" aria-label="{{ __('Pages') }}" style="justify-content:space-between">
    <span class="small muted">{{ __('Page :page of :total', ['page' => $paginator->currentPage(), 'total' => $paginator->lastPage()]) }}</span>
    <span class="actions">
        @if ($paginator->onFirstPage())<span class="btn btn-sm" aria-disabled="true">{{ __('Previous') }}</span>@else<a class="btn btn-sm" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('Previous') }}</a>@endif
        @if ($paginator->hasMorePages())<a class="btn btn-sm" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('Next') }}</a>@else<span class="btn btn-sm" aria-disabled="true">{{ __('Next') }}</span>@endif
    </span>
</nav>
@endif
