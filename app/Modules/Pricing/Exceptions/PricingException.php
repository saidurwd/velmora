<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Exceptions;

use DomainException;

final class PricingException extends DomainException
{
    public static function noPrice(int $variantId): self
    {
        return new self("Variant [{$variantId}] has no price.");
    }

    public static function unknownList(string $code): self
    {
        return new self("Unknown price list [{$code}].");
    }

    public static function currencyMismatch(string $list, string $money): self
    {
        return new self("Price list uses {$list} but the amount is in {$money}.");
    }

    public static function compareAtTooLow(): self
    {
        return new self('The compare-at ("was") price must be higher than the price.');
    }

    public static function saleOverlaps(): self
    {
        return new self('A sale already exists in that period for this variant.');
    }

    public static function badWindow(): self
    {
        return new self('A sale must end after it starts.');
    }

    public static function stalePreview(): self
    {
        return new self('Prices changed since the preview was made. Please preview again.');
    }

    public static function emptyChange(): self
    {
        return new self('There is nothing to change.');
    }
}
