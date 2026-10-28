@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('platform::ui.pagination') }}" class="flex flex-wrap items-center justify-center gap-2">
        @if ($paginator->onFirstPage())
            <span class="inline-flex min-h-11 items-center rounded-md border border-line px-4 text-muted" aria-disabled="true">{{ __('platform::ui.previous') }}</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-flex min-h-11 items-center rounded-md border border-line px-4 hover:bg-surface-alt">{{ __('platform::ui.previous') }}</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="px-1 text-muted" aria-hidden="true">{{ $element }}</span>
            @else
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span aria-current="page" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-md bg-brand-600 px-3 font-medium text-white"><span class="sr-only">{{ __('platform::ui.page') }} </span>{{ \App\Modules\Platform\Support\Fmt::number($page) }}</span>
                    @else
                        <a href="{{ $url }}" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-md border border-line px-3 hover:bg-surface-alt"><span class="sr-only">{{ __('platform::ui.page') }} </span>{{ \App\Modules\Platform\Support\Fmt::number($page) }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-flex min-h-11 items-center rounded-md border border-line px-4 hover:bg-surface-alt">{{ __('platform::ui.next') }}</a>
        @else
            <span class="inline-flex min-h-11 items-center rounded-md border border-line px-4 text-muted" aria-disabled="true">{{ __('platform::ui.next') }}</span>
        @endif
    </nav>
@endif
