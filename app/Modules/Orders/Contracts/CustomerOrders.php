<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

/** Which orders belong to a signed-in customer. Read only; ownership is Orders' own and is never copied elsewhere. */
interface CustomerOrders
{
    /** The order belongs to this account (a guest order that was never claimed belongs to nobody). */
    public function owns(int $userId, int $orderId): bool;

    /**
     * @return list<int> the ids of every order of this account, newest first
     */
    public function orderIdsOwnedBy(int $userId): array;
}
