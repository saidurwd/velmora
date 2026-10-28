<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use DomainException;

final class InventoryException extends DomainException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly ?int $variantId = null)
    {
        parent::__construct($message);
    }

    public static function insufficient(int $variantId, int $requested, int $available): self
    {
        return new self("Only {$available} available for variant {$variantId}, {$requested} requested.", 'stock.insufficient', $variantId);
    }

    public static function belowReserved(int $variantId): self
    {
        return new self("Stock for variant {$variantId} cannot drop below what is already reserved.", 'stock.below_reserved', $variantId);
    }

    public static function reservationNotActive(string $status): self
    {
        return new self("The reservation is {$status} and can no longer be used.", 'stock.reservation_not_active');
    }

    public static function unknownLocation(string $code): self
    {
        return new self("Unknown or inactive stock location [{$code}].", 'stock.unknown_location');
    }

    public static function invariant(string $what): self
    {
        return new self("Stock invariant violated: {$what}", 'stock.invariant');
    }

    public static function invalidQuantity(): self
    {
        return new self('Quantity must be a positive whole number.', 'stock.invalid_quantity');
    }
}
