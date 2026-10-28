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

    public static function typeLocked(): self
    {
        return new self('The product type cannot be changed after the product has left draft.');
    }

    public static function noStockForType(string $type): self
    {
        return new self("{$type} products do not track stock.");
    }

    public static function skuInvalid(string $sku): self
    {
        return new self("SKU [{$sku}] must be 3–64 characters: letters, digits and dashes.");
    }

    public static function skuTaken(string $sku): self
    {
        return new self("SKU [{$sku}] is already used.");
    }

    public static function notPublishable(string $reason): self
    {
        return new self("This product cannot be published: {$reason}.");
    }

    public static function lastVariant(): self
    {
        return new self('A published product must keep at least one active variant.');
    }

    public static function tooManyVariants(int $max): self
    {
        return new self("This would create more than {$max} variants at once.");
    }

    public static function invalidOption(string $why): self
    {
        return new self("Invalid variant option: {$why}.");
    }
}
