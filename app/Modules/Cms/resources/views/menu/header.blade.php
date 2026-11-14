{{-- $links: list<MenuLink>. Large screens. A group opens as a panel: a native <details> (works without JavaScript); resources/js/behaviors.js adds hover, one-open-at-a-time, Escape and click-away. --}}
<nav aria-label="{{ __('cms::menu.header_label') }}" data-menu class="hidden border-t border-line lg:block">
    <ul class="mx-auto flex max-w-7xl flex-wrap items-center px-1.5 text-[0.8125rem] font-medium 2xl:text-sm">
        @foreach ($links as $link)
            @if ($link->isGroup())
                <li class="relative">
                    <details class="group/menu">
                        <summary class="flex min-h-11 cursor-pointer list-none items-center gap-1 rounded-full px-2 hover:text-brand-700 group-open/menu:text-brand-700 [&::-webkit-details-marker]:hidden">
                            {{ $link->label }}
                            <svg aria-hidden="true" class="size-3.5 transition-transform group-open/menu:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 8 5 5 5-5"/></svg>
                        </summary>
                        <div class="absolute top-full z-30 w-max max-w-[min(40rem,90vw)] rounded-2xl border border-line bg-surface p-4 shadow-lift {{ $loop->index >= (int) ceil(count($links) / 2) ? 'end-0' : 'start-0' }}">
                            <ul class="grid grid-cols-2 gap-x-6 font-normal">
                                @foreach ($link->children as $child)<li><a class="block rounded-lg px-3 py-2 hover:bg-brand-50 hover:text-brand-700 {{ $loop->first ? 'font-semibold text-brand-700' : '' }}" href="{{ $child->url }}">{{ $child->label }}</a></li>@endforeach
                            </ul>
                        </div>
                    </details>
                </li>
            @else
                <li><a class="flex min-h-11 items-center rounded-full px-2 hover:text-brand-700" href="{{ $link->url }}">{{ $link->label }}</a></li>
            @endif
        @endforeach
    </ul>
</nav>
