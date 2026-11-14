@props(['name', 'url', 'image' => null, 'imageAlt' => '', 'width' => 320, 'height' => 320, 'srcset' => null, 'price' => null, 'compareAt' => null, 'badge' => null, 'status' => null])
@php
    // "−15%" when there is a real reduction; the badge the caller passed otherwise
    $off = ($price && $compareAt && $compareAt->greaterThan($price) && $compareAt->amountMinor > 0) ? (int) round((1 - $price->amountMinor / $compareAt->amountMinor) * 100) : 0;
@endphp
<article {{ $attributes->class('group relative flex flex-col overflow-hidden rounded-2xl border border-line bg-surface shadow-card transition-shadow duration-200 hover:shadow-lift') }}>
    <div class="relative aspect-[4/5] overflow-hidden bg-surface-alt">
        @if ($image)
            <img src="{{ $image }}" @if ($srcset) srcset="{{ $srcset }}" sizes="(min-width: 1024px) 25vw, (min-width: 640px) 33vw, 50vw" @endif
                 alt="{{ $imageAlt }}" width="{{ $width }}" height="{{ $height }}" loading="lazy" decoding="async" class="size-full object-cover transition-transform duration-300 group-hover:scale-105">
        @else
            {{-- no picture yet: the house motif, so a card never looks broken --}}
            <div class="motif flex size-full items-center justify-center" aria-hidden="true">
                <svg class="size-10 text-brand-600/40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="10" r="1.6"/><path d="m4 17 5-4.5 3.5 3L16 12l4 4"/></svg>
            </div>
        @endif
        @if ($status)<span class="absolute inset-x-0 bottom-0 bg-ink/80 px-3 py-1.5 text-center text-xs font-semibold text-white">{{ $status }}</span>@endif
    </div>
    <div class="absolute start-3 top-3 flex flex-col items-start gap-1">
        @if ($off > 0)<x-platform::badge tone="sale"><span class="sr-only">{{ $badge ?: __('platform::ui.sale') }} </span>−{{ \App\Modules\Platform\Support\Fmt::number($off) }}%</x-platform::badge>
        @elseif ($badge)<x-platform::badge tone="accent">{{ $badge }}</x-platform::badge>@endif
    </div>
    <div class="flex flex-1 flex-col gap-1.5 p-3 sm:p-4">
        <h3 class="line-clamp-2 text-sm font-medium leading-snug sm:text-base"><a href="{{ $url }}" class="after:absolute after:inset-0 group-hover:text-brand-700">{{ $name }}</a></h3>
        @if ($price)<x-platform::price :amount="$price" :compare-at="$compareAt" size="sm" class="mt-auto" />@endif
        {{ $slot }}
    </div>
</article>
