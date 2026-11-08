<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

/** Return requests of one kind (customer or origin) and reason in a period: how many, how many units were asked for, and where they got to. */
final readonly class ReturnReasonRow
{
    public function __construct(
        public string $kind,
        public string $reasonCode,
        public int $requests,
        public int $units,
        public int $approved,
        public int $rejected,
        public int $received,
    ) {}
}
