@extends('layouts.storefront')

@section('title', app(\App\Modules\Platform\Support\Branding::class)->name())

@section('content')
<div class="space-y-12">
    <h1 class="sr-only">{{ app(\App\Modules\Platform\Support\Branding::class)->name() }}@if (app(\App\Modules\Platform\Support\Branding::class)->tagline() !== '') — {{ app(\App\Modules\Platform\Support\Branding::class)->tagline() }}@endif</h1>
    @if ($hero !== [])
        <section aria-roledescription="carousel" aria-label="{{ __('catalog::storefront.promotions') }}" class="space-y-4">
            @foreach ($hero as $banner)
                @include('catalog::storefront.partials.banner', ['banner' => $banner, 'first' => $loop->first])
            @endforeach
        </section>
    @endif

    @if ($categories !== [])
        <section aria-labelledby="cats-h" class="space-y-4">
            <h2 id="cats-h" class="text-2xl font-bold">{{ __('catalog::storefront.shop_by_category') }}</h2>
            <ul class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ($categories as $category)
                    <li><a href="{{ $category['url'] }}" class="flex min-h-20 items-center justify-center rounded-lg border border-line bg-surface-alt p-4 text-center font-medium hover:border-brand-500">{{ $category['name'] }}</a></li>
                @endforeach
            </ul>
        </section>
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
</div>
@endsection
