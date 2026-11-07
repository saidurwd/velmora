<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTOs;

/** One row of the cash-on-delivery aging: `uncollected` (the cash was not recorded yet) or `unremitted` (recorded, but no courier statement covers it), in one age bucket. */
final readonly class CodAgingLine
{
    public function __construct(public string $kind, public string $bucket, public int $count, public int $amountMinor) {}
}
