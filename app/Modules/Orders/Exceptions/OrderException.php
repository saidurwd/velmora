<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use DomainException;

/** `reason` is a translation key under orders::orders.errors. */
final class OrderException extends DomainException
{
    /** @param  array<string, string|int>  $params */
    public function __construct(public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct($reason);
    }
}
