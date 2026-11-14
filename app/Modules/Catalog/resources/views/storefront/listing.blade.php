@extends('layouts.storefront')

@section('title', $title)

@php
    /** Builds a URL for this page with the current filters, changing only what is passed (null removes a key). */
    $query = request()->query();
    $url = function (array $change) use ($basePath, $query) {
        $q = array_filter(array_merge($query, ['page' => null], $change), fn ($v) => $v !== null && $v !== [] && $v !== '');
        return $basePath.($q ? '?'.http_build_query($q) : '');
    };
    $searching = isset($searchQuery);
    $minValue = is_scalar(request('min')) ? (string) request('min') : '';
    $maxValue = is_scalar(request('max')) ? (string) request('max') : '';
    $sortLabels = ($searching ? ['relevance' => __('catalog::storefront.sort.relevance')] : []) + ['newest' => __('catalog::storefront.sort.newest'), 'popular' => __('catalog::storefront.sort.popular'), 'price_asc' => __('catalog::storefront.sort.price_asc'), 'price_desc' => __('catalog::storefront.sort.price_desc')];
@endphp

@if (isset($searchQuery) && $searchQuery !== '' && $products->currentPage() === 1)
    @push('head')<meta name="velmora:search" content="{{ $products->total() }}">@endpush
@endif

@section('content')
<div class="space-y-6">
    <x-platform::breadcrumb :items="$breadcrumb" />

    @if ($searching && ($correctedQuery ?? null))
        <x-platform::alert>{{ __('catalog::storefront.showing_for', ['corrected' => $correctedQuery, 'query' => $searchQuery]) }}</x-platform::alert>
    @endif

    <header class="motif space-y-2 rounded-3xl border border-line bg-surface-alt px-6 py-8 sm:px-10 sm:py-10">
        <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">{{ $title }}</h1>
        @if ($description)<div class="max-w-3xl text-muted">{{ $description }}</div>@endif
    </header>

    @if ($children !== [])
        <nav aria-label="{{ __('catalog::storefront.subcategories') }}">
            <ul class="flex flex-wrap gap-2">
                @foreach ($children as $child)<li><a href="{{ $child['url'] }}" class="inline-flex min-h-11 items-center rounded-full border border-line bg-surface px-4 text-sm font-medium hover:border-brand-600 hover:text-brand-700">{{ $child['name'] }}</a></li>@endforeach
            </ul>
        </nav>
    @endif

    <div class="grid gap-8 lg:grid-cols-[16rem_1fr]">
        {{-- Filters: a plain GET form, so it works without JavaScript. A <details>: closed on phones until asked for; on large screens resources/js/behaviors.js opens it (without scripts its heading stays, to open it by hand). --}}
        <aside aria-label="{{ __('catalog::storefront.filters') }}">
            <details data-open-desktop class="group/filters rounded-2xl border border-line bg-surface p-4 lg:sticky lg:top-36 lg:max-h-[calc(100vh-10rem)] lg:overflow-y-auto lg:p-5" @if ($filters->isFiltered()) open @endif>
                <summary class="flex min-h-11 cursor-pointer items-center font-semibold lg:group-open/filters:hidden">{{ __('catalog::storefront.filters') }}</summary>
                <form method="GET" action="{{ $basePath }}" class="mt-4 space-y-6 lg:group-open/filters:mt-0">
                    <input type="hidden" name="sort" value="{{ $filters->sort }}">
                    @if ($searching)<input type="hidden" name="q" value="{{ $searchQuery }}">@endif

                    @if ($facets['price'])
                        <fieldset class="space-y-2"><legend class="font-semibold">{{ __('catalog::storefront.price') }} (৳)</legend>
                            <div class="flex items-center gap-2">
                                <div><label for="min" class="sr-only">{{ __('catalog::storefront.price_min') }}</label>
                                    <input id="min" name="min" inputmode="decimal" value="{{ $minValue }}" placeholder="{{ \App\Modules\Platform\Support\Fmt::number(intdiv($facets['price']['min'], 100)) }}" class="field-input w-full px-3 py-2"></div>
                                <span aria-hidden="true">–</span>
                                <div><label for="max" class="sr-only">{{ __('catalog::storefront.price_max') }}</label>
                                    <input id="max" name="max" inputmode="decimal" value="{{ $maxValue }}" placeholder="{{ \App\Modules\Platform\Support\Fmt::number(intdiv($facets['price']['max'], 100)) }}" class="field-input w-full px-3 py-2"></div>
                            </div>
                        </fieldset>
                    @endif

                    @if (count($facets['types']) > 1)
                        <fieldset class="space-y-1"><legend class="font-semibold">{{ __('catalog::storefront.type') }}</legend>
                            @foreach ($facets['types'] as $type => $count)
                                <label class="flex min-h-9 items-center gap-2"><input type="checkbox" name="type[]" value="{{ $type }}" @checked(in_array($type, $filters->types, true)) class="size-5 accent-brand-600"> {{ __('catalog::storefront.types.'.$type) }} <span class="text-sm text-muted">({{ \App\Modules\Platform\Support\Fmt::number($count) }})</span></label>
                            @endforeach
                        </fieldset>
                    @endif

                    @if ($facets['brands'] !== [])
                        <fieldset class="space-y-1"><legend class="font-semibold">{{ __('catalog::storefront.brand') }}</legend>
                            @foreach ($facets['brands'] as $brand)
                                <label class="flex min-h-9 items-center gap-2"><input type="checkbox" name="brand[]" value="{{ $brand['slug'] }}" @checked(in_array($brand['slug'], $filters->brands, true)) class="size-5 accent-brand-600"> {{ $brand['name'] }} <span class="text-sm text-muted">({{ \App\Modules\Platform\Support\Fmt::number($brand['count']) }})</span></label>
                            @endforeach
                        </fieldset>
                    @endif

                    @foreach ($facets['attributes'] as $attribute)
                        <fieldset class="space-y-1"><legend class="font-semibold">{{ $attribute['name'] }}</legend>
                            @foreach ($attribute['values'] as $value)
                                <label class="flex min-h-9 items-center gap-2"><input type="checkbox" name="attr[{{ $attribute['code'] }}][]" value="{{ $value['code'] }}" @checked(in_array($value['code'], $filters->attributes[$attribute['code']] ?? [], true)) class="size-5 accent-brand-600"> {{ $value['label'] }} <span class="text-sm text-muted">({{ \App\Modules\Platform\Support\Fmt::number($value['count']) }})</span></label>
                            @endforeach
                        </fieldset>
                    @endforeach

                    <label class="flex min-h-9 items-center gap-2"><input type="checkbox" name="in_stock" value="1" @checked($filters->inStock) class="size-5 accent-brand-600"> {{ __('catalog::storefront.in_stock_only') }}</label>

                    <div class="flex flex-wrap gap-2">
                        <x-platform::btn type="submit">{{ __('catalog::storefront.apply') }}</x-platform::btn>
                        @if ($filters->isFiltered())<x-platform::btn variant="secondary" :href="$url(['brand' => null, 'min' => null, 'max' => null, 'type' => null, 'in_stock' => null, 'attr' => null])">{{ __('catalog::storefront.clear') }}</x-platform::btn>@endif
                    </div>
                </form>
            </details>
        </aside>

        <section aria-labelledby="results-h" class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="results-h" class="text-muted">{{ trans_choice('catalog::storefront.results', $products->total(), ['count' => \App\Modules\Platform\Support\Fmt::number($products->total())]) }}</h2>
                <form method="GET" action="{{ $basePath }}" class="flex items-center gap-2">
                    @foreach (request()->except(['sort', 'page']) as $key => $value)
                        @foreach ((array) $value as $k => $v)
                            @if (is_array($v)) @foreach ($v as $vv)<input type="hidden" name="{{ $key }}[{{ $k }}][]" value="{{ $vv }}">@endforeach
                            @else <input type="hidden" name="{{ $key }}{{ is_array($value) ? '[]' : '' }}" value="{{ $v }}">@endif
                        @endforeach
                    @endforeach
                    <label for="sort" class="text-sm">{{ __('catalog::storefront.sort.label') }}</label>
                    <select id="sort" name="sort" data-autosubmit class="field-input !rounded-full px-4 py-2 text-sm font-medium">
                        @foreach ($sortLabels as $value => $label)<option value="{{ $value }}" @selected($filters->sort === $value)>{{ $label }}</option>@endforeach
                    </select>
                    <noscript><x-platform::btn type="submit" size="sm" variant="secondary">{{ __('catalog::storefront.apply') }}</x-platform::btn></noscript>
                </form>
            </div>

            @if ($products->isEmpty())
                <x-platform::empty-state :title="__('catalog::storefront.none_title')" :text="__('catalog::storefront.none_text')">
                    @if ($filters->isFiltered())<x-platform::btn variant="secondary" :href="$url(['brand' => null, 'min' => null, 'max' => null, 'type' => null, 'in_stock' => null, 'attr' => null])">{{ __('catalog::storefront.clear') }}</x-platform::btn>@endif
                    @if ($searching)<p class="mt-3 text-sm text-muted">{{ __('catalog::storefront.search_tips') }}</p>@endif
                </x-platform::empty-state>
            @else
                <ul class="grid grid-cols-2 gap-3 sm:gap-5 md:grid-cols-3 xl:grid-cols-4">
                    @foreach ($products as $card)
                        <li>@include('catalog::storefront.partials.card', ['card' => $card])</li>
                    @endforeach
                </ul>
                {{ $products->onEachSide(1)->links() }}
            @endif
        </section>
    </div>
</div>
@endsection
