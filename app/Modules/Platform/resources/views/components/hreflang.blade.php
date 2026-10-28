@php
    use App\Modules\Platform\Localization\LocaleUrl;
@endphp
@foreach (LocaleUrl::alternates(request()) as $code => $url)
    <link rel="alternate" hreflang="{{ $code }}" href="{{ $url }}">
@endforeach
<link rel="canonical" href="{{ LocaleUrl::canonical(request()) }}">
