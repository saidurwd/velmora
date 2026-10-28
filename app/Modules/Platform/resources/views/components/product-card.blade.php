@props(['name', 'url', 'image' => null, 'imageAlt' => '', 'width' => 320, 'height' => 320, 'srcset' => null, 'price' => null, 'compareAt' => null, 'badge' => null, 'status' => null])
<article {{ $attributes->class('group relative flex flex-col overflow-hidden rounded-lg border border-line bg-surface') }}>
    <div class="aspect-square bg-surface-alt">
        @if ($image)
            <img src="{{ $image }}" @if ($srcset) srcset="{{ $srcset }}" sizes="(min-width: 1024px) 25vw, (min-width: 640px) 33vw, 50vw" @endif
                 alt="{{ $imageAlt }}" width="{{ $width }}" height="{{ $height }}" loading="lazy" decoding="async" class="size-full object-cover">
        @endif
    </div>
    @if ($badge)<x-platform::badge tone="brand" class="absolute start-2 top-2">{{ $badge }}</x-platform::badge>@endif
    <div class="flex flex-1 flex-col gap-2 p-3">
        <h3 class="line-clamp-2 font-medium"><a href="{{ $url }}" class="after:absolute after:inset-0 hover:underline">{{ $name }}</a></h3>
        @if ($price)<x-platform::price :amount="$price" :compare-at="$compareAt" size="sm" />@endif
        @if ($status)<p class="text-xs text-muted">{{ $status }}</p>@endif
        {{ $slot }}
    </div>
</article>
