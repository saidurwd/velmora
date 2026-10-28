{{-- The first hero image is the likely LCP element: eager + high priority; the rest load lazily. --}}
<div class="relative overflow-hidden rounded-lg bg-surface-alt">
    <img src="{{ $banner->src }}" srcset="{{ $banner->srcset }}" sizes="(min-width: 1152px) 1152px, 100vw" alt="{{ $banner->alt }}"
         width="{{ $banner->width }}" height="{{ $banner->height }}" class="h-auto w-full"
         @if ($first) fetchpriority="high" @else loading="lazy" @endif decoding="async">
    @if ($banner->title || $banner->subtitle || ($banner->ctaLabel && $banner->linkUrl))
        <div class="absolute inset-0 flex flex-col justify-center gap-2 bg-gradient-to-r from-black/60 to-transparent p-6 text-white sm:p-10">
            @if ($banner->title)<p class="max-w-lg text-2xl font-bold sm:text-4xl">{{ $banner->title }}</p>@endif
            @if ($banner->subtitle)<p class="max-w-lg sm:text-lg">{{ $banner->subtitle }}</p>@endif
            @if ($banner->ctaLabel && $banner->linkUrl)<x-platform::btn class="mt-2 self-start" :href="$banner->linkUrl">{{ $banner->ctaLabel }}</x-platform::btn>@endif
        </div>
    @endif
</div>
