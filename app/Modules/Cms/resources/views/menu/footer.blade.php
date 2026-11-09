{{-- $links: list<MenuLink>. Groups become columns; plain links stay in a row. --}}
<nav aria-label="{{ __('cms::menu.footer_label') }}" class="mx-auto max-w-6xl space-y-4 px-4 pt-6">
    @php($plain = array_values(array_filter($links, fn ($l) => ! $l->isGroup())))
    @php($groups = array_values(array_filter($links, fn ($l) => $l->isGroup())))
    @if ($groups !== [])
        <div class="grid gap-6 sm:grid-cols-2 md:grid-cols-4">
            @foreach ($groups as $group)
                <div>
                    <h2 class="mb-2 text-sm font-semibold">{{ $group->label }}</h2>
                    <ul class="space-y-1 text-sm">@foreach ($group->children as $child)<li><a class="hover:underline" href="{{ $child->url }}">{{ $child->label }}</a></li>@endforeach</ul>
                </div>
            @endforeach
        </div>
    @endif
    @if ($plain !== [])
        <ul class="flex flex-wrap gap-x-6 gap-y-2 text-sm">@foreach ($plain as $link)<li><a class="hover:underline" href="{{ $link->url }}">{{ $link->label }}</a></li>@endforeach</ul>
    @endif
</nav>
