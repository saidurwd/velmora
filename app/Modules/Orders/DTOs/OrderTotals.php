<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

/** What an order was worth, for a report on something that was used on it (a coupon): its goods price before discounts, in its currency, and whether it was cancelled. */
final readonly class OrderTotals
{
    public function __construct(public string $currency, public bool $cancelled, public int $grossMinor) {}
}
