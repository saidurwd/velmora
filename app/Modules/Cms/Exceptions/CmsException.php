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

    public static function faqInvalid(): self
    {
        return new self('faq_invalid');
    }

    public static function faqHalf(): self
    {
        return new self('faq_half');
    }

    public static function faqDefaultRequired(): self
    {
        return new self('faq_default_required');
    }

    public static function faqNotFound(): self
    {
        return new self('faq_not_found');
    }

    public static function editConflict(): self
    {
        return new self('edit_conflict');
    }

    public static function versionNotFound(): self
    {
        return new self('version_not_found');
    }

    public static function slugTaken(string $locale, string $slug): self
    {
        return new self('slug_taken', ['locale' => strtoupper($locale), 'slug' => $slug]);
    }

    public static function menuInvalid(): self
    {
        return new self('menu_invalid');
    }

    public static function menuDefaultRequired(string $locale): self
    {
        return new self('menu_default_required', ['locale' => strtoupper($locale)]);
    }

    public static function menuParentInvalid(): self
    {
        return new self('menu_parent_invalid');
    }

    public static function menuHasChildren(): self
    {
        return new self('menu_has_children');
    }

    public static function menuPageMissing(): self
    {
        return new self('menu_page_missing');
    }

    public static function menuNotFound(): self
    {
        return new self('menu_not_found');
    }

    public static function homeInvalid(): self
    {
        return new self('home_invalid');
    }

    public static function homeCollectionMissing(): self
    {
        return new self('home_collection_missing');
    }

    public static function homeBodyRequired(string $locale): self
    {
        return new self('home_body_required', ['locale' => strtoupper($locale)]);
    }

    public static function homeTooLong(string $locale): self
    {
        return new self('home_too_long', ['locale' => strtoupper($locale)]);
    }

    public static function homeFull(): self
    {
        return new self('home_full');
    }

    public static function homeNotFound(): self
    {
        return new self('home_not_found');
    }
}
