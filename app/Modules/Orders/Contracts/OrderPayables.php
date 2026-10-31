<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use App\Modules\Orders\DTOs\OrderPaymentRequest;

/** Which orders can be paid for right now. Payments asks this; it never reads order tables. */
interface OrderPayables
{
    /** The order, if it is still open and unpaid (so paying, or paying again, makes sense). */
    public function payable(string $orderPublicId): ?OrderPaymentRequest;

    public function isPayable(int $orderId): bool;
}
