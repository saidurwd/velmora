<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use App\Modules\Orders\DTOs\ServiceOrderLine;

/** The booked-service lines of an order, for the module that keeps the times. Read only; the order stays Orders' own. */
interface ServiceOrderLines
{
    /** @return list<ServiceOrderLine> in line order; empty when the order has none, is not paid, or does not exist */
    public function paidLinesFor(int $orderId): array;

    /** @return list<ServiceOrderLine> in line order, whatever the order's payment state */
    public function linesFor(int $orderId): array;

    /** One booked-service line by its id; null when there is no such service line. */
    public function lineFor(int $orderItemId): ?ServiceOrderLine;
}
