@props(['items'])
{{-- $items: list of ['label' => string, 'url' => ?string]; the last item is the current page. --}}
<nav aria-label="{{ __('platform::ui.breadcrumb') }}" {{ $attributes }}>
    <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted">
        @foreach ($items as $item)
            <li class="flex items-center gap-2">
                @if ($loop->last || empty($item['url']))
                    <span @if ($loop->last) aria-current="page" @endif class="{{ $loop->last ? 'font-medium text-ink' : '' }}">{{ $item['label'] }}</span>
                @else
                    <a href="{{ $item['url'] }}" class="underline-offset-2 hover:text-brand-700 hover:underline">{{ $item['label'] }}</a>
                @endif
                @unless ($loop->last)<svg aria-hidden="true" class="size-3.5 rtl:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 4 6 6-6 6"/></svg>@endunless
            </li>
        @endforeach
    </ol>
</nav>
