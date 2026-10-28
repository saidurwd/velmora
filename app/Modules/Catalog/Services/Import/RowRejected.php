<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services\Import;

use RuntimeException;

/** One row cannot be applied (e.g. unknown category). Rolls back that row only. */
final class RowRejected extends RuntimeException
{
    /** @param  array<string, string>  $params */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct($key);
    }
}
