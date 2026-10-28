@php
    use App\Modules\Platform\Localization\LocaleResolver;
    use App\Modules\Platform\Localization\LocaleUrl;

    $current = app()->getLocale();
    $labels = config('velmora.locale_labels');
    $localized = LocaleUrl::isLocalized(request());
@endphp
<nav aria-label="{{ __('platform::locale.switcher_label') }}" class="flex items-center gap-2 text-sm">
    @foreach (LocaleResolver::supported() as $locale)
        @if ($locale === $current)
            <span class="font-semibold" aria-current="true" lang="{{ $locale }}">{{ $labels[$locale] ?? $locale }}</span>
        @elseif ($localized)
            <a href="{{ LocaleUrl::switchTo(request(), $locale) }}" hreflang="{{ $locale }}" lang="{{ $locale }}" rel="alternate" class="underline hover:text-brand-700">{{ $labels[$locale] ?? $locale }}</a>
        @else
            <form method="POST" action="{{ route('locale.set', ['locale' => $current]) }}">
                @csrf
                <input type="hidden" name="locale" value="{{ $locale }}">
                <button type="submit" lang="{{ $locale }}" class="underline hover:text-brand-700">{{ $labels[$locale] ?? $locale }}</button>
            </form>
        @endif
        @if (! $loop->last)<span aria-hidden="true">·</span>@endif
    @endforeach
</nav>
