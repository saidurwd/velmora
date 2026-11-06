<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use App\Modules\Orders\DTOs\PurchasedLine;

/** What a customer has really bought, for modules that need proof of purchase (reviews). An order that is not the customer's is simply not there. */
interface PurchasedLines
{
    /** One line of this customer's order, found by the public ids of the order and of the variant; null when it is not theirs or is not in that order. */
    public function find(int $userId, string $orderPublicId, string $variantPublicId): ?PurchasedLine;

    /**
     * Every line of this customer's order, in the order they were bought; empty when the order is not theirs.
     *
     * @return list<PurchasedLine>
     */
    public function forOrder(int $userId, string $orderPublicId): array;
}
