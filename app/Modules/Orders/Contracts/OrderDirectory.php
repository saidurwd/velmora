<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

/** Order references for reports in other modules (for example "which orders used this coupon"). */
interface OrderDirectory
{
    /**
     * @param  list<int>  $orderIds
     * @return array<int, array{number: string, public_id: string}> keyed by order id
     */
    public function references(array $orderIds): array;

    /** @return array{id: int, number: string, public_id: string}|null the order with this order number (as printed, any case) */
    public function findByNumber(string $orderNumber): ?array;
}
