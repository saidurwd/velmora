<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

/** What one signed-in customer ordered in one shop month (`Y-m`): live (not cancelled) orders and their goods price after discounts. The customer is a number, never a name or a contact. */
final readonly class CustomerMonth
{
    public function __construct(public int $customerId, public string $month, public int $orders, public int $salesMinor) {}
}
