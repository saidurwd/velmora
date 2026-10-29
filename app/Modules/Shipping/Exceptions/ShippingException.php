<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Exceptions;

use DomainException;

/** `reason` is a key under shipping::admin.errors. */
final class ShippingException extends DomainException
{
    /** @param  array<string, string|int>  $params */
    public function __construct(public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct($reason);
    }
}
