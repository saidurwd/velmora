<?php

declare(strict_types=1);

namespace App\Modules\Cms\Exceptions;

use DomainException;

/** `reason` is a translation key under cms::admin.errors, so staff read the problem in their own language. */
final class CmsException extends DomainException
{
    /** @param  array<string, string>  $params */
    public function __construct(public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct($reason);
    }

    public static function defaultLanguageRequired(string $locale): self
    {
        return new self('default_language_required', ['locale' => strtoupper($locale)]);
    }

    public static function unsupportedLanguage(string $locale): self
    {
        return new self('unsupported_language', ['locale' => $locale]);
    }

    public static function notPublishable(string $why): self
    {
        return new self('not_publishable_'.$why);
    }

    public static function unsafeLink(): self
    {
        return new self('unsafe_link');
    }

    public static function badWindow(): self
    {
        return new self('bad_window');
    }

    public static function imageRequired(string $locale): self
    {
        return new self('image_required', ['locale' => strtoupper($locale)]);
    }

    public static function altRequired(string $locale): self
    {
        return new self('alt_required', ['locale' => strtoupper($locale)]);
    }

    public static function image(string $reason): self
    {
        return new self('image_'.$reason);
    }
}
