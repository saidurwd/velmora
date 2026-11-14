{{-- The first hero image is the likely LCP element: eager + high priority; the rest load lazily. --}}
<div class="relative overflow-hidden rounded-3xl bg-surface-alt shadow-card">
    <img src="{{ $banner->src }}" srcset="{{ $banner->srcset }}" sizes="(min-width: 1280px) 1248px, 100vw" alt="{{ $banner->alt }}"
         width="{{ $banner->width }}" height="{{ $banner->height }}" class="h-auto min-h-56 w-full object-cover"
         @if ($first) fetchpriority="high" @else loading="lazy" @endif decoding="async">
    @if ($banner->title || $banner->subtitle || ($banner->ctaLabel && $banner->linkUrl))
        <div class="absolute inset-0 flex flex-col justify-center gap-3 bg-gradient-to-r from-black/65 via-black/30 to-transparent p-6 text-white sm:p-12">
            @if ($banner->title)<p class="max-w-xl text-3xl font-bold leading-tight tracking-tight sm:text-5xl">{{ $banner->title }}</p>@endif
            @if ($banner->subtitle)<p class="max-w-lg text-base sm:text-xl">{{ $banner->subtitle }}</p>@endif
            @if ($banner->ctaLabel && $banner->linkUrl)<x-platform::btn variant="accent" class="mt-2 self-start" :href="$banner->linkUrl">{{ $banner->ctaLabel }}<svg aria-hidden="true" class="size-4 rtl:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10h12m-5-5 5 5-5 5"/></svg></x-platform::btn>@endif
        </div>
    @endif
</div>
