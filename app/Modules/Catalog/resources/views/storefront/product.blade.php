@extends('layouts.storefront')

@section('title', $page->seoTitle ?: $page->name)

@push('head')<meta name="velmora:item" content="{{ $page->publicId }}">@endpush

@section('content')
@php
    $stockTone = ['in_stock' => 'success', 'low_stock' => 'warning', 'out_of_stock' => 'danger'][$page->stockState] ?? null;
@endphp
<div class="space-y-10">
    <x-platform::breadcrumb :items="$page->breadcrumb" />

    <div class="grid gap-8 lg:grid-cols-2">
        {{-- Gallery: CSS scroll-snap, so it swipes on phones and scrolls with the keyboard, no JavaScript needed. --}}
        <section aria-label="{{ __('catalog::storefront.gallery') }}">
            @if ($page->gallery !== [])
                <ul class="flex snap-x snap-mandatory gap-2 overflow-x-auto rounded-lg" tabindex="0" aria-label="{{ __('catalog::storefront.gallery_hint', ['count' => count($page->gallery)]) }}">
                    @foreach ($page->gallery as $i => $img)
                        <li class="w-full shrink-0 snap-center">
                            <img src="{{ $img['src'] }}" srcset="{{ $img['srcset'] }}" sizes="(min-width: 1024px) 560px, 100vw" alt="{{ $img['alt'] }}"
                                 width="{{ $img['width'] }}" height="{{ $img['height'] }}" class="aspect-square w-full rounded-lg bg-surface-alt object-cover"
                                 @if ($i === 0) fetchpriority="high" @else loading="lazy" @endif decoding="async">
                        </li>
                    @endforeach
                </ul>
                @if (count($page->gallery) > 1)<p class="mt-2 text-sm text-muted">{{ __('catalog::storefront.gallery_hint', ['count' => \App\Modules\Platform\Support\Fmt::number(count($page->gallery))]) }}</p>@endif
            @else
                <div class="aspect-square rounded-lg bg-surface-alt" role="img" aria-label="{{ __('catalog::storefront.no_image') }}"></div>
            @endif
        </section>

        <section class="space-y-5" aria-labelledby="product-h">
            <div class="space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    @if ($page->type !== 'physical')<x-platform::badge tone="brand">{{ __('catalog::storefront.types.'.$page->type) }}</x-platform::badge>@endif
                    @if ($page->onSale)<x-platform::badge tone="danger">{{ __('platform::ui.sale') }}</x-platform::badge>@endif
                </div>
                <h1 id="product-h" class="text-3xl font-bold">{{ $page->name }}</h1>
                @if ($page->brandName)<p><a class="text-brand-700 underline underline-offset-2" href="{{ $page->brandUrl }}">{{ $page->brandName }}</a></p>@endif
                @if ($page->shortDescription)<p class="text-muted">{{ $page->shortDescription }}</p>@endif
            </div>

            <div class="space-y-1" aria-live="polite">
                <x-platform::price :amount="$page->price" :compare-at="$page->compareAt" size="lg" />
                @if ($page->saleEndsAt)<p class="text-sm text-danger">{{ __('catalog::storefront.sale_ends', ['when' => \App\Modules\Platform\Support\Fmt::dateTime($page->saleEndsAt)]) }}</p>@endif
                @if ($page->taxNote)<p class="text-sm text-muted">{{ __('catalog::storefront.tax_'.$page->taxNote) }}</p>@endif
            </div>

            {{-- Variant picker: a plain GET form (works without JavaScript); choosing an option reloads the page with that variant. --}}
            @if ($page->options !== [])
                <form method="GET" action="{{ $productPath }}" class="space-y-4">
                    @foreach ($page->options as $option)
                        <fieldset class="space-y-2"><legend class="font-medium">{{ $option['name'] }}</legend>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($option['values'] as $value)
                                    <label class="relative">
                                        <input type="radio" name="opt[{{ $option['code'] }}]" value="{{ $value['code'] }}" @checked($value['selected']) onchange="this.form.submit()" class="peer sr-only">
                                        <span class="inline-flex min-h-11 min-w-11 cursor-pointer items-center justify-center rounded-md border px-4 peer-checked:border-brand-600 peer-checked:bg-brand-50 peer-checked:font-semibold peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 {{ $value['inStock'] ? 'border-line' : 'border-dashed border-line text-muted line-through' }}">
                                            {{ $value['label'] }}@unless ($value['inStock'])<span class="sr-only"> — {{ __('platform::ui.out_of_stock') }}</span>@endunless
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach
                    <noscript><x-platform::btn type="submit" variant="secondary">{{ __('catalog::storefront.update_choice') }}</x-platform::btn></noscript>
                </form>
            @endif

            <div class="space-y-3 rounded-lg border border-line p-4">
                @if ($stockTone)<p><x-platform::badge :tone="$stockTone">{{ __('platform::ui.'.$page->stockState) }}</x-platform::badge></p>@endif
                <p class="text-sm text-muted">{{ __('catalog::storefront.sku') }}: {{ $page->sku }}</p>
                <p class="text-sm text-muted">{{ __('catalog::storefront.note_'.$page->type) }}</p>
                {{-- Ordering arrives with the cart (Phase 5): the control is visible but cannot be used yet. --}}
                <x-platform::btn type="button" disabled aria-disabled="true" class="w-full cursor-not-allowed opacity-60">{{ __('catalog::storefront.add_to_cart') }}</x-platform::btn>
                <p class="text-sm text-muted">{{ __('catalog::storefront.ordering_soon') }}</p>
            </div>
        </section>
    </div>

    @if ($page->descriptionHtml)
        <section aria-labelledby="desc-h" class="max-w-3xl space-y-3">
            <h2 id="desc-h" class="text-xl font-semibold">{{ __('catalog::storefront.description') }}</h2>
            <div class="space-y-3 leading-relaxed [&_a]:text-brand-700 [&_a]:underline [&_ol]:list-decimal [&_ol]:ps-6 [&_ul]:list-disc [&_ul]:ps-6">{!! $page->descriptionHtml !!}</div>
        </section>
    @endif

    @if ($related !== [])
        @include('catalog::storefront.partials.rail', ['id' => 'related', 'title' => __('catalog::storefront.related'), 'url' => url('/'.app()->getLocale().'/shop'), 'cards' => $related])
    @endif

    {{-- Recently viewed: kept only in this browser (localStorage); nothing is sent to the server. --}}
    <section x-data="recentlyViewed(@js(['id' => $page->publicId, 'name' => $page->name, 'url' => $productPath, 'image' => $page->gallery[0]['src'] ?? null]))" x-show="items.length" x-cloak style="display:none" aria-labelledby="rv-h" class="space-y-4">
        <h2 id="rv-h" class="text-2xl font-bold">{{ __('catalog::storefront.recently_viewed') }}</h2>
        <ul class="grid grid-cols-2 gap-4 md:grid-cols-4">
            <template x-for="item in items" :key="item.id">
                <li class="space-y-2"><a :href="item.url" class="block"><img x-show="item.image" :src="item.image" alt="" width="160" height="160" loading="lazy" class="aspect-square w-full rounded-lg bg-surface-alt object-cover"><span class="mt-1 block font-medium" x-text="item.name"></span></a></li>
            </template>
        </ul>
    </section>
</div>
@endsection
