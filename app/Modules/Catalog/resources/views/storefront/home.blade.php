@extends('layouts.storefront')

@section('title', app(\App\Modules\Platform\Support\Branding::class)->name())

@section('content')
<div class="space-y-12 sm:space-y-16">
    <h1 class="sr-only">{{ app(\App\Modules\Platform\Support\Branding::class)->name() }}@if (app(\App\Modules\Platform\Support\Branding::class)->tagline() !== '') — {{ app(\App\Modules\Platform\Support\Branding::class)->tagline() }}@endif</h1>
    @if ($sections !== [])
        @foreach ($sections as $i => $section)
            @switch($section['type'])
                @case('banner_hero')
                    @include('catalog::storefront.partials.hero', ['banners' => $section['banners'], 'label' => $section['title'] ?? __('catalog::storefront.promotions'), 'lcp' => $i === 0])
                    @if ($i === 0)@include('partials.storefront.value-props')@endif
                    @break
                @case('banner_strip')
                    @foreach ($section['banners'] as $banner)
                        @include('catalog::storefront.partials.banner', ['banner' => $banner, 'first' => false])
                    @endforeach
                    @break
                @case('categories')
                    @include('catalog::storefront.partials.categories', ['id' => 'sec-'.$i, 'title' => $section['title'] ?? __('catalog::storefront.shop_by_category'), 'categories' => $section['categories']])
                    @break
                @case('collection')
                    @include('catalog::storefront.partials.rail', ['id' => 'sec-'.$i, 'title' => $section['title'], 'url' => $section['url'], 'cards' => $section['cards']])
                    @break
                @case('new_arrivals')
                    @include('catalog::storefront.partials.rail', ['id' => 'sec-'.$i, 'title' => $section['title'] ?? __('catalog::storefront.new_arrivals'), 'url' => $section['url'], 'cards' => $section['cards']])
                    @break
                @case('popular')
                    @include('catalog::storefront.partials.rail', ['id' => 'sec-'.$i, 'title' => $section['title'] ?? __('catalog::storefront.popular'), 'url' => $section['url'], 'cards' => $section['cards']])
                    @break
                @case('text')
                    <section @if ($section['title']) aria-labelledby="sec-{{ $i }}-h" @endif class="space-y-3">
                        @if ($section['title'])<h2 id="sec-{{ $i }}-h" class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $section['title'] }}</h2>@endif
                        <div class="space-y-3 leading-relaxed [&_a]:text-brand-700 [&_a]:underline [&_h2]:text-xl [&_h2]:font-semibold [&_h3]:font-semibold [&_ol]:list-decimal [&_ol]:ps-6 [&_ul]:list-disc [&_ul]:ps-6">{!! $section['body'] !!}</div>
                    </section>
                    @break
            @endswitch
        @endforeach
    @else
    @if ($hero !== [])
        @include('catalog::storefront.partials.hero', ['banners' => $hero, 'label' => __('catalog::storefront.promotions'), 'lcp' => true])
    @endif

    @include('partials.storefront.value-props')

    @if ($categories !== [])
        @include('catalog::storefront.partials.categories', ['id' => 'cats', 'title' => __('catalog::storefront.shop_by_category'), 'categories' => $categories])
    @endif

    @foreach ($strip as $banner)
        @include('catalog::storefront.partials.banner', ['banner' => $banner, 'first' => false])
    @endforeach

    @foreach ($collections as $i => $rail)
        @include('catalog::storefront.partials.rail', ['id' => 'col-'.$i, 'title' => $rail['title'], 'url' => $rail['url'], 'cards' => $rail['cards']])
    @endforeach

    @if ($newest !== [])
        @include('catalog::storefront.partials.rail', ['id' => 'new', 'title' => __('catalog::storefront.new_arrivals'), 'url' => url('/'.app()->getLocale().'/shop'), 'cards' => $newest])
    @endif

    @if ($popular !== [])
        @include('catalog::storefront.partials.rail', ['id' => 'popular', 'title' => __('catalog::storefront.popular'), 'url' => url('/'.app()->getLocale().'/shop?sort=popular'), 'cards' => $popular])
    @endif

    @if ($hero === [] && $categories === [] && $newest === [] && $collections === [])
        <x-platform::empty-state :title="__('catalog::storefront.coming_soon_title')" :text="__('catalog::storefront.coming_soon_text')" />
    @endif
    @endif
</div>
@endsection
