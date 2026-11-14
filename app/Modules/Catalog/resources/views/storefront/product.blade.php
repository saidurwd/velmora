@extends('layouts.storefront')

@section('title', $page->seoTitle ?: $page->name)

@push('head')<meta name="velmora:item" content="{{ $page->publicId }}">@endpush

@section('content')
@php
    $stockTone = ['in_stock' => 'success', 'low_stock' => 'warning', 'out_of_stock' => 'danger'][$page->stockState] ?? null;
@endphp
<div class="space-y-10">
    <x-platform::breadcrumb :items="$page->breadcrumb" />

    <div class="grid gap-8 lg:grid-cols-2 lg:gap-12">
        {{-- Gallery: CSS scroll-snap, so it swipes on phones and scrolls with the keyboard, no JavaScript needed. --}}
        <section aria-label="{{ __('catalog::storefront.gallery') }}">
            @if ($page->gallery !== [])
                <ul class="scroll-row flex snap-x snap-mandatory gap-2 overflow-x-auto rounded-3xl" tabindex="0" aria-label="{{ __('catalog::storefront.gallery_hint', ['count' => count($page->gallery)]) }}">
                    @foreach ($page->gallery as $i => $img)
                        <li class="w-full shrink-0 snap-center">
                            <img src="{{ $img['src'] }}" srcset="{{ $img['srcset'] }}" sizes="(min-width: 1024px) 560px, 100vw" alt="{{ $img['alt'] }}"
                                 width="{{ $img['width'] }}" height="{{ $img['height'] }}" class="aspect-square w-full rounded-3xl bg-surface-alt object-cover"
                                 @if ($i === 0) fetchpriority="high" @else loading="lazy" @endif decoding="async">
                        </li>
                    @endforeach
                </ul>
                @if (count($page->gallery) > 1)<p class="mt-2 text-sm text-muted">{{ __('catalog::storefront.gallery_hint', ['count' => \App\Modules\Platform\Support\Fmt::number(count($page->gallery))]) }}</p>@endif
            @else
                <div class="motif flex aspect-square items-center justify-center rounded-3xl border border-line bg-surface-alt" role="img" aria-label="{{ __('catalog::storefront.no_image') }}"><svg aria-hidden="true" class="size-16 text-brand-600/40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="10" r="1.6"/><path d="m4 17 5-4.5 3.5 3L16 12l4 4"/></svg></div>
            @endif
        </section>

        <section class="space-y-5" aria-labelledby="product-h">
            <div class="space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    @if ($page->type !== 'physical')<x-platform::badge tone="brand">{{ __('catalog::storefront.types.'.$page->type) }}</x-platform::badge>@endif
                    @if ($page->onSale)<x-platform::badge tone="sale">{{ __('platform::ui.sale') }}</x-platform::badge>@endif
                </div>
                <h1 id="product-h" class="text-3xl font-bold tracking-tight sm:text-4xl">{{ $page->name }}</h1>
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
                                        <input type="radio" name="opt[{{ $option['code'] }}]" value="{{ $value['code'] }}" @checked($value['selected']) data-autosubmit class="peer sr-only">
                                        <span class="inline-flex min-h-11 min-w-11 cursor-pointer items-center justify-center rounded-full border px-5 peer-checked:border-brand-600 peer-checked:bg-brand-600 peer-checked:font-semibold peer-checked:text-white peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 {{ $value['inStock'] ? 'border-line' : 'border-dashed border-line text-muted line-through' }}">
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

            <div class="space-y-3 rounded-2xl border border-line bg-surface-alt p-5">
                @if ($stockTone)<p><x-platform::badge :tone="$stockTone">{{ __('platform::ui.'.$page->stockState) }}</x-platform::badge></p>@endif
                <p class="text-sm text-muted">{{ __('catalog::storefront.sku') }}: {{ $page->sku }}</p>
                <p class="text-sm text-muted">{{ __('catalog::storefront.note_'.$page->type) }}</p>
                @error('cart')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror
                @if ($page->type === 'service' && \Illuminate\Support\Facades\Route::has('bookings.book'))
                    <x-platform::btn :href="route('bookings.book', ['locale' => app()->getLocale(), 'sku' => $page->sku])" class="w-full">{{ __('bookings::book.choose_time') }}</x-platform::btn>
                @elseif ($page->stockState === 'out_of_stock')
                    <x-platform::btn type="button" disabled aria-disabled="true" class="w-full cursor-not-allowed opacity-60">{{ __('platform::ui.out_of_stock') }}</x-platform::btn>
                @else
                    @php
                        $qtyHelp = array_filter([
                            $page->qtyStep > 1 || $page->minQty > 1 ? __('catalog::storefront.qty_rule', ['min' => $page->minQty, 'step' => $page->qtyStep]) : null,
                            $page->maxQty !== null ? __('catalog::storefront.qty_max', ['max' => $page->maxQty]) : null,
                        ]);
                    @endphp
                    <form method="POST" action="{{ route('cart.add', ['locale' => app()->getLocale()]) }}" class="flex flex-wrap items-end gap-3" novalidate>
                        @csrf
                        <input type="hidden" name="variant" value="{{ $page->variantPublicId }}">
                        <div>
                            <label for="qty" class="mb-1 block text-sm font-medium">{{ __('cart::cart.quantity') }}</label>
                            <x-platform::qty-stepper id="qty" :value="$page->minQty" :min="$page->minQty" :max="$page->maxQty" :step="$page->qtyStep" :describedby="$qtyHelp !== [] ? 'qty-help' : null" />
                        </div>
                        <x-platform::btn type="submit" class="min-w-48 flex-1"><svg aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a1 1 0 0 0 1 .8h9.1a1 1 0 0 0 1-.8L21 7H6"/></svg>{{ __('cart::cart.add') }}</x-platform::btn>
                    </form>
                    @if ($qtyHelp !== [])<p id="qty-help" class="text-sm text-muted">{{ implode(' ', $qtyHelp) }}</p>@endif
                @endif
                @includeIf('engagement::partials.product-actions', ['variant' => $page->variantPublicId, 'type' => $page->type, 'outOfStock' => $page->stockState === 'out_of_stock'])
            </div>
        </section>
    </div>

    @if ($page->type === 'physical')@include('partials.storefront.value-props')@endif

    @if ($page->type === 'digital')@include('digital-products::partials.refund-policy', ['skus' => [$page->sku]])@endif
    @if ($page->type === 'service')@include('bookings::partials.cancellation-policy', ['skus' => [$page->sku]])@endif

    @if ($page->descriptionHtml)
        <section aria-labelledby="desc-h" class="max-w-3xl space-y-3">
            <h2 id="desc-h" class="text-2xl font-bold tracking-tight">{{ __('catalog::storefront.description') }}</h2>
            <div class="space-y-3 leading-relaxed [&_a]:text-brand-700 [&_a]:underline [&_ol]:list-decimal [&_ol]:ps-6 [&_ul]:list-disc [&_ul]:ps-6">{!! $page->descriptionHtml !!}</div>
        </section>
    @endif

    @includeIf('engagement::partials.product-reviews', ['variant' => $page->variantPublicId])

    @if ($related !== [])
        @include('catalog::storefront.partials.rail', ['id' => 'related', 'title' => __('catalog::storefront.related'), 'url' => url('/'.app()->getLocale().'/shop'), 'cards' => $related])
    @endif

    {{-- Recently viewed: kept only in this browser (localStorage); nothing is sent to the server. --}}
    <section x-data="recentlyViewed" data-current="{{ json_encode(['id' => $page->publicId, 'name' => $page->name, 'url' => $productPath, 'image' => $page->gallery[0]['src'] ?? null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" x-show="items.length" x-cloak style="display:none" aria-labelledby="rv-h" class="space-y-4">
        <h2 id="rv-h" class="text-2xl font-bold tracking-tight sm:text-3xl">{{ __('catalog::storefront.recently_viewed') }}</h2>
        <ul class="grid grid-cols-2 gap-4 md:grid-cols-4">
            <template x-for="item in items" :key="item.id">
                <li class="space-y-2"><a :href="item.url" class="block"><img x-show="item.image" :src="item.image" alt="" width="160" height="160" loading="lazy" class="aspect-square w-full rounded-2xl bg-surface-alt object-cover"><span class="mt-1 block font-medium" x-text="item.name"></span></a></li>
            </template>
        </ul>
    </section>
</div>
@endsection
