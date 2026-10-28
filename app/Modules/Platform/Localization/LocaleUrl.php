<?php

declare(strict_types=1);

namespace App\Modules\Platform\Localization;

use Illuminate\Http\Request;

/** Builds the same page in another locale (switcher), hreflang alternates and the canonical URL. */
final class LocaleUrl
{
    /** True when the current path starts with a supported locale segment (/en/..., /bn/...). */
    public static function isLocalized(Request $request): bool
    {
        return LocaleResolver::isSupported($request->segment(1));
    }

    /** Same path and query in another locale; null for pages that are not locale-prefixed. */
    public static function switchTo(Request $request, string $locale): ?string
    {
        if (! self::isLocalized($request)) {
            return null;
        }

        $path = '/'.$locale.substr($request->getPathInfo(), strlen('/'.$request->segment(1)));
        $query = $request->getQueryString();

        return $request->getSchemeAndHttpHost().$path.($query ? '?'.$query : '');
    }

    /** @return array<string, string> hreflang => url, including x-default (English) */
    public static function alternates(Request $request): array
    {
        if (! self::isLocalized($request)) {
            return [];
        }

        $urls = [];
        foreach (LocaleResolver::supported() as $locale) {
            $urls[$locale] = (string) self::switchTo($request, $locale);
        }
        $urls['x-default'] = $urls[LocaleResolver::default()] ?? (string) reset($urls);

        return $urls;
    }

    /**
     * Canonical = this locale's URL without tracking/filter noise: path only unless allow-listed params are present.
     *
     * @param  list<string>  $keep
     */
    public static function canonical(Request $request, array $keep = ['page', 'q', 'category', 'brand', 'sort']): string
    {
        $query = array_intersect_key($request->query(), array_flip($keep));
        ksort($query);

        return $request->getSchemeAndHttpHost().$request->getPathInfo().($query ? '?'.http_build_query($query) : '');
    }
}
