{{-- $links: list<MenuLink>. Shown on the dark footer. Groups become columns; plain links stay in a row. --}}
<nav aria-label="{{ __('cms::menu.footer_label') }}" class="space-y-6">
    @php($plain = array_values(array_filter($links, fn ($l) => ! $l->isGroup())))
    @php($groups = array_values(array_filter($links, fn ($l) => $l->isGroup())))
    @if ($groups !== [])
        <div class="grid gap-8 sm:grid-cols-2 md:grid-cols-3">
            @foreach ($groups as $group)
                <div>
                    <h2 class="mb-3 text-sm font-semibold text-white">{{ $group->label }}</h2>
                    <ul class="space-y-2 text-sm">@foreach ($group->children as $child)<li><a class="underline-offset-2 hover:text-white hover:underline" href="{{ $child->url }}">{{ $child->label }}</a></li>@endforeach</ul>
                </div>
            @endforeach
        </div>
    @endif
    @if ($plain !== [])
        <ul class="flex flex-wrap gap-x-6 gap-y-2 text-sm">@foreach ($plain as $link)<li><a class="underline-offset-2 hover:text-white hover:underline" href="{{ $link->url }}">{{ $link->label }}</a></li>@endforeach</ul>
    @endif
</nav>
