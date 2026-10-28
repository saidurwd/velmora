@props(['items'])
{{-- $items: list of ['label' => string, 'url' => ?string]; the last item is the current page. --}}
<nav aria-label="{{ __('platform::ui.breadcrumb') }}" {{ $attributes }}>
    <ol class="flex flex-wrap items-center gap-x-2 text-sm text-muted">
        @foreach ($items as $item)
            <li class="flex items-center gap-2">
                @if ($loop->last || empty($item['url']))
                    <span @if ($loop->last) aria-current="page" @endif class="{{ $loop->last ? 'text-ink' : '' }}">{{ $item['label'] }}</span>
                @else
                    <a href="{{ $item['url'] }}" class="hover:underline">{{ $item['label'] }}</a>
                @endif
                @unless ($loop->last)<span aria-hidden="true" class="rtl:rotate-180">›</span>@endunless
            </li>
        @endforeach
    </ol>
</nav>
