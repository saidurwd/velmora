<?php

declare(strict_types=1);

namespace App\Modules\Customers\Exceptions;

use DomainException;

/** `reason` is a translation key under customers::account.addresses.errors. */
final class AddressException extends DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public static function limitReached(): self
    {
        return new self('limit_reached');
    }

    public static function phoneInvalid(): self
    {
        return new self('phone_invalid');
    }

    public static function locationMismatch(): self
    {
        return new self('location_mismatch');
    }

    public static function notFound(): self
    {
        return new self('not_found');
    }

    public static function postalInvalid(): self
    {
        return new self('postal_invalid');
    }
}
