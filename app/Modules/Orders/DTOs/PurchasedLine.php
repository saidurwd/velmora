<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

use Carbon\CarbonImmutable;

/**
 * One line of one customer's own order, as another module may know it: enough to say what was bought, when, and whether the order's state allows a review of it
 * (`reviewable`, with the `reason` when not). Nothing about money or other people.
 */
final readonly class PurchasedLine
{
    public function __construct(
        public int $orderItemId,
        public int $variantId,
        public string $productType,
        public string $orderPublicId,
        public CarbonImmutable $placedAt,
        public bool $reviewable,
        public ?string $reason = null,
    ) {}
}
