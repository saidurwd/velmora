<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use DomainException;

final class CatalogException extends DomainException
{
    public static function defaultLanguageRequired(string $locale): self
    {
        return new self("Content in the default language [{$locale}] is required.");
    }

    public static function unsupportedLanguage(string $locale): self
    {
        return new self("Language [{$locale}] is not supported.");
    }

    public static function cycle(): self
    {
        return new self('A category cannot be moved under itself or one of its own sub-categories.');
    }

    public static function tooDeep(int $max): self
    {
        return new self("Categories can be nested at most {$max} levels deep.");
    }

    public static function notEmpty(string $what): self
    {
        return new self("This {$what} still has content and cannot be deleted.");
    }
}
