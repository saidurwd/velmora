<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Exceptions;

use RuntimeException;

/** A refusal the customer can be told about. `reason` is a key under engagement::errors; `params` fill its blanks. */
final class EngagementException extends RuntimeException
{
    /** @param  array<string, string|int>  $params */
    public function __construct(public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct($reason);
    }
}
