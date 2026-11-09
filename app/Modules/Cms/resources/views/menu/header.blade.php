{{-- $links: list<MenuLink>. A group opens as an accessible details list; no JavaScript needed. --}}
<nav aria-label="{{ __('cms::menu.header_label') }}" class="border-b border-line bg-surface">
    <ul class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-1 px-4 py-2 text-sm">
        @foreach ($links as $link)
            @if ($link->isGroup())
                <li><details class="relative"><summary class="cursor-pointer py-2 hover:underline">{{ $link->label }}</summary>
                    <ul class="absolute z-10 mt-1 min-w-48 space-y-1 rounded-md border border-line bg-surface p-3 shadow">
                        @foreach ($link->children as $child)<li><a class="block py-1 hover:underline" href="{{ $child->url }}">{{ $child->label }}</a></li>@endforeach
                    </ul></details></li>
            @else
                <li><a class="block py-2 hover:underline" href="{{ $link->url }}">{{ $link->label }}</a></li>
            @endif
        @endforeach
    </ul>
</nav>
