<?php

declare(strict_types=1);

namespace App\Modules\Cart\Exceptions;

use DomainException;

/** `reason` is a translation key under cart::cart.errors. */
final class CartException extends DomainException
{
    /** @param  array<string, string|int>  $params */
    public function __construct(public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct($reason);
    }
}
