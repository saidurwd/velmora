<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

/** The few things about an order that other modules need to place a payment or a refund in a report: how it was placed, in which language, and whether it was cancelled. */
final readonly class OrderFact
{
    public function __construct(public string $channel, public string $locale, public bool $cancelled) {}
}
