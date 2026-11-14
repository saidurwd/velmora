{{-- $links: list<MenuLink>. Phones: the same menu as a list; each group opens in place (native <details>, no JavaScript needed). --}}
<nav aria-label="{{ __('cms::menu.header_label') }}" class="mx-auto max-w-7xl px-4 py-2">
    <ul class="divide-y divide-line">
        @foreach ($links as $link)
            @if ($link->isGroup())
                <li>
                    <details class="group/menu">
                        <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 font-medium [&::-webkit-details-marker]:hidden">
                            {{ $link->label }}
                            <svg aria-hidden="true" class="size-4 shrink-0 transition-transform group-open/menu:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 8 5 5 5-5"/></svg>
                        </summary>
                        <ul class="mb-3 grid grid-cols-2 gap-x-4">
                            @foreach ($link->children as $child)<li><a class="block rounded-lg px-2 py-2.5 text-sm hover:bg-brand-50 {{ $loop->first ? 'font-semibold text-brand-700' : '' }}" href="{{ $child->url }}">{{ $child->label }}</a></li>@endforeach
                        </ul>
                    </details>
                </li>
            @else
                <li><a class="flex min-h-12 items-center font-medium" href="{{ $link->url }}">{{ $link->label }}</a></li>
            @endif
        @endforeach
    </ul>
</nav>
