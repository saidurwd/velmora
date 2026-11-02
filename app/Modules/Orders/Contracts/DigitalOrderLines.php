<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use App\Modules\Orders\DTOs\DigitalOrderLine;

/** The digital lines of an order, for the module that delivers them. Read only; the order stays Orders' own. */
interface DigitalOrderLines
{
    /**
     * @return list<DigitalOrderLine> in line order; empty when the order has none, is not paid, or does not exist
     */
    public function paidLinesFor(int $orderId): array;
}
