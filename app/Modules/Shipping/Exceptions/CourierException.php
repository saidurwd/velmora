<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Exceptions;

use DomainException;

/** A courier problem with a reason key that has a translation under `shipping::admin.errors`. */
final class CourierException extends DomainException
{
    /** @param  array<string, scalar>  $params */
    public function __construct(public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct($reason);
    }
}
