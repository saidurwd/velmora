<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use DomainException;

/** A refusal with a reason key that has a translation under `inventory::ops.errors`. */
final class StockOpsException extends DomainException
{
    /** @param  array<string, scalar>  $params */
    public function __construct(public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct($reason);
    }
}
