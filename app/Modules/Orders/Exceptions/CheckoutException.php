<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use DomainException;

/** Why an order could not be placed. `reason` is a key under orders::checkout.errors; nothing has been written when this is thrown. */
final class CheckoutException extends DomainException
{
    /** @param  array<string, string|int>  $params */
    public function __construct(public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct($reason);
    }
}
