<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use DomainException;

/** `reason` is a key under payments::payments.errors. */
final class PaymentException extends DomainException
{
    /** @param  array<string, string|int>  $params */
    public function __construct(public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct($reason);
    }
}
