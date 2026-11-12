<?php

declare(strict_types=1);

namespace App\Modules\Platform\Localization;

use Illuminate\Http\Request;

/** Locale precedence (BD-33): URL prefix -> signed-in user -> cookie -> Accept-Language -> default. The app API reverses the first two for an explicit Accept-Language header. */
final class LocaleResolver
{
    public const COOKIE = 'locale';

    /** @return list<string> */
    public static function supported(): array
    {
        /** @var list<string> */
        return config('app.supported_locales');
    }

    public static function default(): string
    {
        return (string) config('app.default_locale');
    }

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && in_array($locale, self::supported(), true);
    }

    public function resolve(Request $request): string
    {
        // Routes without a {locale} parameter (the 404 fallback) still carry the prefix in the path.
        $fromUrl = $request->route('locale') ?? $request->segment(1);
        if (is_string($fromUrl) && self::isSupported($fromUrl)) {
            return $fromUrl;
        }

        // An app says which language it is showing in every request. That choice beats the language saved on the account (the account's is the fallback when
        // the app sends none), so switching language inside the app takes effect at once.
        if ($request->is('api/*') && $request->headers->has('Accept-Language')) {
            $asked = $request->getPreferredLanguage(self::supported());
            if (self::isSupported($asked)) {
                return (string) $asked;
            }
        }

        $user = $request->user();
        $fromUser = $user === null ? null : ($user->locale ?? null);
        if (is_string($fromUser) && self::isSupported($fromUser)) {
            return $fromUser;
        }

        $fromCookie = $request->cookie(self::COOKIE);
        if (is_string($fromCookie) && self::isSupported($fromCookie)) {
            return $fromCookie;
        }

        // Accept-Language: bn-BD,bn;q=0.9,en;q=0.8 -> "bn"
        $preferred = $request->getPreferredLanguage(self::supported());

        return self::isSupported($preferred) ? (string) $preferred : self::default();
    }
}
