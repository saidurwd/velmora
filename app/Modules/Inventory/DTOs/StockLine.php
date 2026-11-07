<?php

declare(strict_types=1);

namespace App\Modules\Inventory\DTOs;

/** One product in one place that needs attention: at or below its low level, or none left to sell. `threshold` is null when nobody set a low level. */
final readonly class StockLine
{
    public function __construct(public int $variantId, public string $location, public int $onHand, public int $reserved, public int $available, public ?int $threshold) {}
}
