@props(['title' => ''])
@php
    use App\Modules\Platform\Localization\LocaleUrl;
    use App\Modules\Platform\Support\Branding;
    use App\Modules\Platform\Support\PageSeo;

    /** @var PageSeo $seo */
    $seo = app(PageSeo::class);
    $brand = app(Branding::class);
    $locale = app()->getLocale();
    $canonical = $seo->canonical ?? LocaleUrl::canonical(request());
    $alternates = $seo->alternates !== [] ? $seo->alternates : LocaleUrl::alternates(request());
    $ogLocale = ['en' => 'en_US', 'bn' => 'bn_BD'][$locale] ?? $locale;
@endphp
@if ($seo->noindex)<meta name="robots" content="noindex, follow">@endif
@if ($seo->description)<meta name="description" content="{{ $seo->description }}">@endif
<link rel="canonical" href="{{ $canonical }}">
@foreach ($alternates as $code => $url)<link rel="alternate" hreflang="{{ $code }}" href="{{ $url }}">@endforeach
<meta property="og:site_name" content="{{ $brand->name() }}">
<meta property="og:type" content="{{ $seo->type }}">
<meta property="og:title" content="{{ $title !== '' ? $title : $brand->name() }}">
@if ($seo->description)<meta property="og:description" content="{{ $seo->description }}">@endif
<meta property="og:url" content="{{ $canonical }}">
<meta property="og:locale" content="{{ $ogLocale }}">
@foreach (array_keys($alternates) as $code)@if (! in_array($code, [$locale, 'x-default'], true))<meta property="og:locale:alternate" content="{{ ['en' => 'en_US', 'bn' => 'bn_BD'][$code] ?? $code }}">@endif @endforeach
@if ($seo->image)<meta property="og:image" content="{{ $seo->image }}">@endif
<meta name="twitter:card" content="{{ $seo->image ? 'summary_large_image' : 'summary' }}">
@foreach ($seo->jsonLd as $data)<script type="application/ld+json">{!! PageSeo::encode($data) !!}</script>@endforeach
