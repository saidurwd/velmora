@props(['name', 'url', 'summary' => null, 'price' => null, 'duration' => null])
<article {{ $attributes->class('relative flex flex-col gap-2 rounded-lg border border-line bg-surface p-4') }}>
    <x-platform::badge tone="brand" class="self-start">{{ __('platform::ui.service') }}</x-platform::badge>
    <h3 class="font-medium"><a href="{{ $url }}" class="after:absolute after:inset-0 hover:underline">{{ $name }}</a></h3>
    @if ($summary)<p class="line-clamp-3 text-sm text-muted">{{ $summary }}</p>@endif
    <div class="mt-auto flex items-center justify-between gap-2">
        @if ($price)<x-platform::price :amount="$price" size="sm" />@endif
        @if ($duration)<span class="text-xs text-muted">{{ $duration }}</span>@endif
    </div>
</article>
