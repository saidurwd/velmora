@extends('layouts.storefront')

@section('title', $title)

@push('head')
    @if ($metaDescription)<meta name="description" content="{{ \Illuminate\Support\Str::limit(strip_tags($metaDescription), 160) }}">@endif
@endpush

@php
    /** Builds a URL for this page with the current filters, changing only what is passed (null removes a key). */
    $query = request()->query();
    $url = function (array $change) use ($basePath, $query) {
        $q = array_filter(array_merge($query, ['page' => null], $change), fn ($v) => $v !== null && $v !== [] && $v !== '');
        return $basePath.($q ? '?'.http_build_query($q) : '');
    };
    $sortLabels = ['newest' => __('catalog::storefront.sort.newest'), 'popular' => __('catalog::storefront.sort.popular'), 'price_asc' => __('catalog::storefront.sort.price_asc'), 'price_desc' => __('catalog::storefront.sort.price_desc')];
@endphp

@section('content')
<div class="space-y-6">
    <x-platform::breadcrumb :items="$breadcrumb" />

    <header class="space-y-2">
        <h1 class="text-3xl font-bold">{{ $title }}</h1>
        @if ($description)<div class="max-w-3xl text-muted">{!! \Illuminate\Support\Str::limit($description, 600) !!}</div>@endif
    </header>

    @if ($children !== [])
        <nav aria-label="{{ __('catalog::storefront.subcategories') }}">
            <ul class="flex flex-wrap gap-2">
                @foreach ($children as $child)<li><a href="{{ $child['url'] }}" class="inline-flex min-h-11 items-center rounded-full border border-line px-4 hover:bg-surface-alt">{{ $child['name'] }}</a></li>@endforeach
            </ul>
        </nav>
    @endif

    <div class="grid gap-8 lg:grid-cols-[16rem_1fr]">
        {{-- Filters: a plain GET form, so it works without JavaScript. On small screens it collapses into a <details>. --}}
        <aside aria-label="{{ __('catalog::storefront.filters') }}">
            <details class="rounded-lg border border-line p-4 lg:border-0 lg:p-0" @if ($filters->isFiltered()) open @endif>
                <summary class="min-h-11 cursor-pointer font-semibold lg:hidden">{{ __('catalog::storefront.filters') }}</summary>
                <form method="GET" action="{{ $basePath }}" class="mt-4 space-y-6 lg:mt-0">
                    <input type="hidden" name="sort" value="{{ $filters->sort }}">

                    @if ($facets['price'])
                        <fieldset class="space-y-2"><legend class="font-medium">{{ __('catalog::storefront.price') }} (৳)</legend>
                            <div class="flex items-center gap-2">
                                <div><label for="min" class="sr-only">{{ __('catalog::storefront.price_min') }}</label>
                                    <input id="min" name="min" inputmode="decimal" value="{{ request('min') }}" placeholder="{{ \App\Modules\Platform\Support\Fmt::number(intdiv($facets['price']['min'], 100)) }}" class="w-full rounded-md border border-line px-2 py-2"></div>
                                <span aria-hidden="true">–</span>
                                <div><label for="max" class="sr-only">{{ __('catalog::storefront.price_max') }}</label>
                                    <input id="max" name="max" inputmode="decimal" value="{{ request('max') }}" placeholder="{{ \App\Modules\Platform\Support\Fmt::number(intdiv($facets['price']['max'], 100)) }}" class="w-full rounded-md border border-line px-2 py-2"></div>
                            </div>
                        </fieldset>
                    @endif

                    @if (count($facets['types']) > 1)
                        <fieldset class="space-y-1"><legend class="font-medium">{{ __('catalog::storefront.type') }}</legend>
                            @foreach ($facets['types'] as $type => $count)
                                <label class="flex min-h-9 items-center gap-2"><input type="checkbox" name="type[]" value="{{ $type }}" @checked(in_array($type, $filters->types, true)) class="size-5"> {{ __('catalog::storefront.types.'.$type) }} <span class="text-sm text-muted">({{ \App\Modules\Platform\Support\Fmt::number($count) }})</span></label>
                            @endforeach
                        </fieldset>
                    @endif

                    @if ($facets['brands'] !== [])
                        <fieldset class="space-y-1"><legend class="font-medium">{{ __('catalog::storefront.brand') }}</legend>
                            @foreach ($facets['brands'] as $brand)
                                <label class="flex min-h-9 items-center gap-2"><input type="checkbox" name="brand[]" value="{{ $brand['slug'] }}" @checked(in_array($brand['slug'], $filters->brands, true)) class="size-5"> {{ $brand['name'] }} <span class="text-sm text-muted">({{ \App\Modules\Platform\Support\Fmt::number($brand['count']) }})</span></label>
                            @endforeach
                        </fieldset>
                    @endif

                    @foreach ($facets['attributes'] as $attribute)
                        <fieldset class="space-y-1"><legend class="font-medium">{{ $attribute['name'] }}</legend>
                            @foreach ($attribute['values'] as $value)
                                <label class="flex min-h-9 items-center gap-2"><input type="checkbox" name="attr[{{ $attribute['code'] }}][]" value="{{ $value['code'] }}" @checked(in_array($value['code'], $filters->attributes[$attribute['code']] ?? [], true)) class="size-5"> {{ $value['label'] }} <span class="text-sm text-muted">({{ \App\Modules\Platform\Support\Fmt::number($value['count']) }})</span></label>
                            @endforeach
                        </fieldset>
                    @endforeach

                    <label class="flex min-h-9 items-center gap-2"><input type="checkbox" name="in_stock" value="1" @checked($filters->inStock) class="size-5"> {{ __('catalog::storefront.in_stock_only') }}</label>

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
                    <select id="sort" name="sort" onchange="this.form.submit()" class="rounded-md border border-line px-3 py-2">
                        @foreach ($sortLabels as $value => $label)<option value="{{ $value }}" @selected($filters->sort === $value)>{{ $label }}</option>@endforeach
                    </select>
                    <noscript><x-platform::btn type="submit" size="sm" variant="secondary">{{ __('catalog::storefront.apply') }}</x-platform::btn></noscript>
                </form>
            </div>

            @if ($products->isEmpty())
                <x-platform::empty-state :title="__('catalog::storefront.none_title')" :text="__('catalog::storefront.none_text')">
                    @if ($filters->isFiltered())<x-platform::btn variant="secondary" :href="$url(['brand' => null, 'min' => null, 'max' => null, 'type' => null, 'in_stock' => null, 'attr' => null])">{{ __('catalog::storefront.clear') }}</x-platform::btn>@endif
                </x-platform::empty-state>
            @else
                <ul class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-4">
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
