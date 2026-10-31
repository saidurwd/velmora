<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use App\Modules\Orders\DTOs\CodOrderInfo;

/** Cash-on-delivery facts about orders. Payments asks this; it never reads order tables. */
interface CodOrders
{
    public function find(string $orderPublicId): ?CodOrderInfo;

    /**
     * Cash-on-delivery orders that are on their way or delivered but whose cash has not been recorded yet (oldest first).
     *
     * @return list<CodOrderInfo>
     */
    public function awaitingCash(): array;
}
